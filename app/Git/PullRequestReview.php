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

    /** @return array<string, string> */
    public function marks(string $scope): array
    {
        return Storage::disk('local')->json('pull-reviews/'.hash('sha256', $scope).'.json') ?? [];
    }

    public function mark(string $scope, string $id, string $version, bool $viewed): void
    {
        Cache::lock($scope.':write', 10)->block(5, function () use ($scope, $id, $version, $viewed): void {
            $marks = $this->marks($scope);
            if ($viewed) {
                $marks[$id] = $version;
            } elseif (($marks[$id] ?? null) === $version) {
                unset($marks[$id]);
            }

            $path = Storage::disk('local')->path('pull-reviews/'.hash('sha256', $scope).'.json');
            File::ensureDirectoryExists(dirname($path));
            File::replace($path, json_encode($marks, JSON_THROW_ON_ERROR));
        });
    }
}
