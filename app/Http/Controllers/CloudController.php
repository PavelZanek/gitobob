<?php

namespace App\Http\Controllers;

use App\Git\GitHubClient;
use App\Git\RepositoryScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Shell;
use RuntimeException;

final class CloudController
{
    public function index(Request $request, RepositoryScanner $scanner, GitHubClient $github): View
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

        $key = $github->cacheKey($repository);
        $snapshot = $key === null ? null : Cache::get($key);

        return view('cloud', compact('repository', 'local', 'snapshot', 'noRemote'));
    }

    public function data(Request $request, GitHubClient $github): Response
    {
        $repository = $this->repository($request);
        $key = $github->cacheKey($repository);
        try {
            $snapshot = $github->overview($repository) + ['updated' => now()->utc()->toIso8601String()];
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

        return response()->view('cloud-data', compact('repository', 'snapshot'))->header('Cache-Control', 'no-store');
    }

    public function pull(Request $request, GitHubClient $github, int $number): Response
    {
        $repository = $this->repository($request);
        abort_if($repository === null, 404);
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT);
        abort_unless(is_int($page) && $page >= 1 && $page <= ceil(GitHubClient::MAX_PR_FILES / GitHubClient::FILES_PER_PAGE), 404);
        $file = $request->query('file');
        abort_unless($file === null || (is_string($file) && preg_match('/^[a-f0-9]{64}$/D', $file)), 404);
        $key = $github->cacheKey($repository.'/pull/'.$number.'/page/'.$page);
        $cached = $request->boolean('snapshot') && $key !== null ? Cache::get($key) : null;
        $error = null;
        $detail = null;
        try {
            if (is_string($cached)) {
                $detail = json_decode(Crypt::decryptString($cached), true, flags: JSON_THROW_ON_ERROR);
            } else {
                $detail = $github->pullRequest($repository, $number, $page);
                if ($key !== null) {
                    Cache::put($key, Crypt::encryptString(json_encode($detail, JSON_THROW_ON_ERROR)), now()->addMinutes(2));
                }
            }
        } catch (RuntimeException $exception) {
            if (! in_array($exception->getCode(), [401, 403, 503], true)) {
                throw $exception;
            }
            if ($key !== null) {
                Cache::forget($key);
            }
            $error = $exception->getMessage();
        }

        $files = [];
        foreach ($detail['files'] ?? [] as $entry) {
            $files[hash('sha256', $entry['filename'])] = $entry;
        }
        $selected = $files[$file ?? array_key_first($files)] ?? null;
        $count = $detail['pull']['changed_files'] ?? 0;
        $pages = (int) ceil(min($count, GitHubClient::MAX_PR_FILES) / GitHubClient::FILES_PER_PAGE);

        return response()->view('cloud-pull', compact('repository', 'number', 'detail', 'files', 'selected', 'count', 'page', 'pages', 'file', 'error'))
            ->header('Cache-Control', 'no-store');
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
