<?php

namespace App\Git;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final readonly class PullRequestReview
{
    public function __construct(private GitHubClient $github) {}

    /** @return array<string, mixed> */
    public function detail(string $scope, string $repository, int $number, int $page, bool $snapshot, ?string $revision = null): array
    {
        $key = $scope.':files:'.$page;
        $cached = $snapshot ? Cache::get($key) : null;
        if (is_string($cached)) {
            $detail = json_decode(Crypt::decryptString($cached), true, flags: JSON_THROW_ON_ERROR);
            if ($revision === null || $revision === $this->revision($detail['pull'])) {
                return $detail;
            }
        }

        Cache::forget($key);
        $detail = $this->github->pullRequest($repository, $number, $page);
        Cache::put($key, Crypt::encryptString(json_encode($detail, JSON_THROW_ON_ERROR)), now()->addMinutes(2));

        return $detail;
    }

    /** @param array<string, mixed> $pull */
    public function revision(array $pull): string
    {
        return ($pull['head_sha'] ?? '').':'.($pull['base_sha'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $pull
     * @return list<array<string, mixed>>
     */
    public function index(string $scope, string $repository, int $number, array $pull): array
    {
        $key = $scope.':index:'.$this->revision($pull);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return json_decode(Crypt::decryptString($cached), true, flags: JSON_THROW_ON_ERROR);
        }

        $files = [];
        $limit = min($pull['changed_files'], GitHubClient::MAX_PR_FILES);
        for ($offset = 0; $offset < $limit; $offset += 100) {
            $batch = $this->github->filePage('repos/'.$repository.'/pulls/'.$number, intdiv($offset, 100) + 1, 100);
            foreach ($batch as $entry) {
                unset($entry['patch']);
                $entry['source_page'] = intdiv(count($files), GitHubClient::FILES_PER_PAGE) + 1;
                $files[] = $entry;
            }
        }
        Cache::put($key, Crypt::encryptString(json_encode($files, JSON_THROW_ON_ERROR)), now()->addMinutes(10));

        return $files;
    }

    /** @return array{marks: array<string, string>, last?: string, total?: int, revision?: string} */
    public function state(string $scope): array
    {
        $stored = Storage::disk('local')->json('pull-reviews/'.hash('sha256', $scope).'.json') ?? [];

        return isset($stored['marks']) ? $stored : ['marks' => $stored];
    }

    /**
     * @param  list<array<string, mixed>>  $pulls
     * @return array<int, array{viewed: int, total: ?int}>
     */
    public function summaries(string $login, string $repository, array $pulls): array
    {
        $progress = [];
        foreach ($pulls as $pull) {
            $state = $this->state($this->github->scopeKey($login, $repository.'/pull/'.$pull['number']));
            if (($state['total'] ?? 0) > 0 || $state['marks'] !== []) {
                $progress[$pull['number']] = ['viewed' => count($state['marks']), 'total' => $state['total'] ?? null];
            }
        }

        return $progress;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, string>  $marks
     */
    public function resumePosition(array $files, array $marks, ?string $last): int
    {
        $start = (int) array_search($last, array_column($files, 'id'), true);
        $count = count($files);
        for ($offset = 0; $offset < $count; $offset++) {
            $position = ($start + $offset) % $count;
            $entry = $files[$position];
            if (($marks[$entry['id']] ?? null) !== $entry['version']) {
                return $position;
            }
        }

        return $start;
    }

    /** @param array<string, string> $outdated */
    public function remember(string $scope, ?string $file, int $total, string $revision, array $outdated): void
    {
        $this->update($scope, static function (array $state) use ($file, $total, $revision, $outdated): array {
            $state['marks'] = array_diff_assoc($state['marks'], $outdated);
            if ($file !== null) {
                $state['last'] = $file;
            }
            $state['total'] = $total;
            $state['revision'] = $revision;

            return $state;
        });
    }

    public function mark(string $scope, string $id, string $version, bool $viewed): void
    {
        $this->update($scope, static function (array $state) use ($id, $version, $viewed): array {
            if ($viewed) {
                $state['marks'][$id] = $version;
            } elseif (($state['marks'][$id] ?? null) === $version) {
                unset($state['marks'][$id]);
            }

            return $state;
        });
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    private function update(string $scope, callable $change): void
    {
        Cache::lock($scope.':write', 10)->block(5, function () use ($scope, $change): void {
            $state = $this->state($scope);
            $updated = $change($state);
            if ($state === $updated) {
                return;
            }

            $path = Storage::disk('local')->path('pull-reviews/'.hash('sha256', $scope).'.json');
            File::ensureDirectoryExists(dirname($path));
            File::replace($path, json_encode($updated, JSON_THROW_ON_ERROR));
        });
    }
}
