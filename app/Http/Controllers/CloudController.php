<?php

namespace App\Http\Controllers;

use App\Git\GitHubClient;
use App\Git\PullRequestReview;
use App\Git\RepositoryScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Shell;
use RuntimeException;

final class CloudController
{
    public function index(Request $request, RepositoryScanner $scanner, GitHubClient $github, PullRequestReview $review): View
    {
        $repository = $this->repository($request);
        $local = $request->query('local');
        $noRemote = false;
        if ($local !== null) {
            $root = Storage::disk('local')->get('workspace.path');
            abort_unless(is_string($local) && is_string($root), 404);
            $directory = $scanner->repositoryDirectory($root, $local);
            abort_if($directory === null, 404);
            $repository ??= $github->remote($directory);
            $noRemote = $repository === null;
        }

        $login = $github->configuredAccount();
        $key = $login === null ? null : $github->scopeKey($login, $repository);
        $snapshot = $key === null ? null : Cache::get($key);

        $progress = $repository !== null && $login !== null && is_array($snapshot)
            ? $review->summaries($login, $repository, $snapshot['pulls']) : [];

        return view('cloud', compact('repository', 'local', 'snapshot', 'noRemote', 'progress'));
    }

    public function data(Request $request, GitHubClient $github, PullRequestReview $review): Response
    {
        $repository = $this->repository($request);
        $login = $github->configuredAccount();
        $key = $login === null ? null : $github->scopeKey($login, $repository);
        try {
            $snapshot = $github->overview($repository) + ['updated' => now()->utc()->toIso8601String()];
            $progress = $repository === null ? [] : $review->summaries($login ?? $github->authenticatedAccount(), $repository, $snapshot['pulls']);
        } catch (RuntimeException $exception) {
            if (! in_array($exception->getCode(), [401, 403, 503], true)) {
                throw $exception;
            }
            if ($key !== null && $exception->getCode() !== 503) {
                Cache::forget($key);
            }

            return response($exception->getMessage(), $exception->getCode(), ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }
        if ($key !== null) {
            Cache::put($key, $snapshot, now()->addDay());
        }

        return response()->view('cloud-data', compact('repository', 'snapshot', 'progress'))->header('Cache-Control', 'no-store');
    }

    public function pull(Request $request, GitHubClient $github, PullRequestReview $review, int $number): Response
    {
        $repository = $this->repository($request);
        abort_if($repository === null, 404);
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT);
        $size = GitHubClient::FILES_PER_PAGE;
        abort_unless(is_int($page) && $page >= 1 && $page <= ceil(GitHubClient::MAX_PR_FILES / $size), 404);
        $file = $request->query('file');
        abort_unless($file === null || (is_string($file) && preg_match('/^[a-f0-9]{64}$/D', $file)), 404);
        $filter = $request->query('filter') ?? '';
        abort_unless(is_string($filter) && mb_strlen($filter) <= 1024, 404);
        $hide = $request->boolean('hide');
        $filtered = trim($filter) !== '' || $hide;
        $last = $request->query('edge') === 'last';
        $resume = $request->boolean('resume');
        $preview = $request->header('X-GitoBob-Preview') === '1';
        $refresh = $request->hasHeader('X-GitoBob-Revision');
        $source = $filtered || $resume || $refresh ? 1 : $page;

        try {
            $scope = $github->reviewKey($repository, $number);
            $state = $review->state($scope);
            $snapshot = $request->boolean('snapshot') || $request->header('X-GitoBob-Warmed') === '1';
            $metadata = $refresh ? $github->pullMetadata($repository, $number) : null;
            if ($metadata !== null && hash('sha256', $scope.$review->revision($metadata)) === $request->header('X-GitoBob-Revision')) {
                return response('', 204)->header('Cache-Control', 'no-store');
            }
            $detail = $review->detail($scope, $repository, $number, $source, ! $refresh && $snapshot, metadata: $metadata);
            $revision = $review->revision($detail['pull']);
            if (! $refresh && $snapshot && isset($state['revision']) && $state['revision'] !== $revision) {
                $detail = $review->detail($scope, $repository, $number, $source, false);
                $revision = $review->revision($detail['pull']);
            }
            $marks = $state['marks'];
            $count = (int) $detail['pull']['changed_files'];
            $total = min($count, GitHubClient::MAX_PR_FILES);
            $entries = $detail['files'];
            $available = $total;
            $reconcile = $marks !== [] && ($state['revision'] ?? null) !== $revision;
            $all = $entries;
            if ($total > $size && ($filtered || $resume || $reconcile || $refresh)) {
                $all = $review->index($scope, $repository, $number, $detail['pull']);
            }
            if ($reconcile && $total <= $size && $source !== 1) {
                $all = $review->detail($scope, $repository, $number, 1, true, $revision)['files'];
            }
            $outdated = $reconcile || ($total <= $size && $source === 1)
                ? array_diff_assoc($marks, array_column($all, 'version', 'id')) : [];
            $marks = array_diff_assoc($marks, $outdated);

            if ($resume || $filtered || $refresh) {
                $entries = $all;
                $entries = array_values(array_filter($entries, static fn (array $entry): bool => (! $hide || ($marks[$entry['id']] ?? null) !== $entry['version'])
                    && (mb_stripos($entry['filename'], trim($filter)) !== false
                        || mb_stripos($entry['previous_filename'] ?? '', trim($filter)) !== false)
                ));
                $total = count($entries);
                if ($refresh) {
                    $cursor = array_search($file, array_column($entries, 'id'), true);
                    if ($cursor === false) {
                        $cursor = min(($page - 1) * $size + max(0, min($size - 1, $request->integer('slot'))), max(0, $total - 1));
                    }
                    $file = $entries[$cursor]['id'] ?? null;
                    $page = intdiv($cursor, $size) + 1;
                } elseif ($resume) {
                    $cursor = $review->resumePosition($entries, $marks, $state['last'] ?? null);
                    $file = $entries[$cursor]['id'] ?? null;
                    $page = intdiv($cursor, $size) + 1;
                }
                $lastPage = max(1, (int) ceil($total / $size));
                $last = $last || $page > $lastPage;
                $page = min($page, $lastPage);
                $entries = array_slice($entries, ($page - 1) * $size, $size);
            }
            $files = array_column($entries, null, 'id');
            $ids = array_keys($files);
            $slot = $last ? count($ids) - 1 : min(max(0, $request->integer('slot')), count($ids) - 1);
            $id = $file ?? ($ids[$slot] ?? null);
            $selected = $files[$id] ?? null;
            if (isset($selected['source_page'])) {
                $patchPage = $selected['source_page'] === $source ? $detail
                    : $review->detail($scope, $repository, $number, $selected['source_page'], true, $review->revision($detail['pull']));
                $selected = array_column($patchPage['files'], null, 'id')[$id] ?? null;
            }
            $position = array_search($id, $ids, true);
            $pages = (int) ceil($total / $size);
            if (! $preview) {
                $review->remember($scope, $selected['id'] ?? null, $available, $revision, $outdated);
            }

            return response()->view('cloud-pull', compact('repository', 'number', 'detail', 'files', 'selected', 'count', 'page', 'pages', 'file', 'filter', 'hide', 'total', 'position', 'ids', 'size', 'marks', 'scope', 'revision'))
                ->header('Cache-Control', 'no-store');
        } catch (RuntimeException $exception) {
            if (! in_array($exception->getCode(), [401, 403, 503], true)) {
                throw $exception;
            }

            return response()->view('cloud-pull', ['error' => $exception->getMessage(), 'detail' => null, 'repository' => $repository, 'number' => $number,
                'page' => $page, 'file' => $file, 'filter' => $filter, 'hide' => $hide], $refresh && $exception->getCode() === 503 ? 503 : 200)->header('Cache-Control', 'no-store');
        }
    }

    public function viewed(Request $request, GitHubClient $github, PullRequestReview $review, int $number): RedirectResponse
    {
        $repository = $this->repository($request);
        abort_if($repository === null, 404);
        $data = $request->validate([
            'scope' => ['required', 'string', 'size:64'],
            'id' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'viewed' => ['required', 'boolean'],
            'filter' => ['nullable', 'string', 'max:1024'],
            'hide' => ['nullable', 'boolean'],
            'page' => ['integer', 'min:1', 'max:100'],
            'slot' => ['integer', 'min:0', 'max:29'],
        ]);
        $scope = $github->reviewKey($repository, $number);
        abort_unless(hash_equals(hash('sha256', $scope), $data['scope']), 409);
        $review->mark($scope, $data['id'], $data['version'], $request->boolean('viewed'));

        return redirect()->route('cloud.pull', ['repository' => $repository, 'number' => $number, 'snapshot' => 1,
            'file' => $request->boolean('hide') ? null : $data['id']] + $request->only('filter', 'hide', 'page', 'slot'));
    }

    public function openPull(Request $request, int $number): Response
    {
        $repository = $this->repository($request);
        abort_if($repository === null, 404);
        Shell::openExternal('https://github.com/'.$repository.'/pull/'.$number.'/files');

        return response()->noContent();
    }

    private function repository(Request $request): ?string
    {
        abort_unless(in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            && in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
        $repository = $request->query('repository');
        abort_unless($repository === null || (is_string($repository) && preg_match('~^'.GitHubClient::REPOSITORY.'$~D', $repository)), 404);

        return $repository;
    }
}
