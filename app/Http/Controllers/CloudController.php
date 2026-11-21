<?php

namespace App\Http\Controllers;

use App\Git\GitHubClient;
use App\Git\RepositoryScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
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

    private function repository(Request $request): ?string
    {
        abort_unless(in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)
            && in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
        $repository = $request->query('repository');
        abort_unless($repository === null || (is_string($repository) && preg_match('~^'.GitHubClient::REPOSITORY.'$~D', $repository)), 404);

        return $repository;
    }
}
