<?php

namespace App\Git;

use App\Preferences;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Discover working trees independently of the parent repository's ignore rules.
 */
final class RepositoryScanner
{
    private const array EXCLUDED = ['.', '..', '.git', 'vendor', 'node_modules'];

    public function repositoryDirectory(string $root, string $relative): ?string
    {
        $directory = realpath($root);
        if ($directory === false) {
            return null;
        }

        if ($relative !== '.') {
            foreach (explode('/', $relative) as $name) {
                if ($name === '' || str_contains($name, "\0") || in_array($name, self::EXCLUDED, true) || strcasecmp($name, '.git') === 0) {
                    return null;
                }
                $directory .= '/'.$name;
                if (is_link($directory) || ! is_dir($directory)) {
                    return null;
                }
            }
        }

        return file_exists($directory.'/.git') ? $directory : null;
    }

    public function workspaceTree(string $root, bool $reuse): ?RepositoryFolder
    {
        $depth = Preferences::read()->depth;
        $key = 'workspace-tree-v2:'.$this->key($root, $depth);
        if ($reuse) {
            $snapshot = Cache::get($key);
            if (is_array($snapshot)) {
                return $snapshot['tree'];
            }
        }

        $tree = $this->scan($root, $depth);
        Cache::put($key, ['tree' => $tree], now()->addMinutes(5));

        return $tree;
    }

    /**
     * @return array{tree:?RepositoryFolder, token:?string, count:int}
     */
    public function browse(string $root, bool $reuse, bool $opening, ?string $token): array
    {
        $depth = Preferences::read()->depth;
        $key = $this->key($root, $depth);

        return Cache::lock('workspace-scan-lock:'.$key, 120)->block(60, function () use ($root, $reuse, $opening, $token, $key, $depth): array {
            $active = 'workspace-scan-dfs:'.$key;
            $snapshot = 'workspace-tree-v2:'.$key;
            $scan = Cache::get($active);
            if ($token !== null) {
                if (! is_array($scan) && ($cached = Cache::get($snapshot)) && ($cached['token'] ?? null) === $token) {
                    return ['tree' => $cached['tree'], 'token' => null, 'count' => $cached['tree']?->repositoryCount() ?? 0];
                }
                abort_unless(is_array($scan) && $scan['token'] === $token, 409);
            } elseif ($reuse || $opening) {
                if (! is_array($scan) && is_array($cached = Cache::get($snapshot))) {
                    return ['tree' => $cached['tree'], 'token' => null, 'count' => $cached['tree']?->repositoryCount() ?? 0];
                }
            } else {
                $scan = null;
            }
            if (! is_array($scan)) {
                $scan = ['token' => (string) Str::uuid(), 'pending' => ['.'], 'repositories' => []];
            }

            if (! $opening) {
                $this->walk($root, $scan['pending'], $scan['repositories'], $depth, hrtime(true) + 250_000_000);
            }
            $tree = RepositoryFolder::fromRepositories(basename($root) ?: '/', $scan['repositories']);
            $complete = $scan['pending'] === [];
            if ($complete) {
                Cache::put($snapshot, ['tree' => $tree, 'token' => $scan['token']], now()->addMinutes(5));
                Cache::forget($active);
            } else {
                Cache::put($active, $scan, now()->addMinutes(10));
            }

            return ['tree' => $tree, 'token' => $complete ? null : $scan['token'], 'count' => count($scan['repositories'])];
        });
    }

    public function forgetWorkspace(): void
    {
        $root = Storage::disk('local')->get('workspace.path');
        if (is_string($root)) {
            $key = $this->key($root, Preferences::read()->depth);
            Cache::forget('workspace-tree-v2:'.$key);
            Cache::forget('workspace-scan-dfs:'.$key);
        }
    }

    private function key(string $root, ?int $depth): string
    {
        return hash('sha256', $root."\0".($depth ?? 'all'));
    }

    public function scan(string $directory, ?int $depth = Preferences::DEFAULT_DEPTH): ?RepositoryFolder
    {
        $pending = ['.'];
        $repositories = [];
        $this->walk($directory, $pending, $repositories, $depth);

        return RepositoryFolder::fromRepositories(basename($directory) ?: '/', $repositories);
    }

    /**
     * @param  list<string>  $pending
     * @param  array<string,RepositoryStatus>  $repositories
     */
    private function walk(string $root, array &$pending, array &$repositories, ?int $depth, ?int $deadline = null): void
    {
        $root = realpath($root);
        if ($root === false) {
            $pending = [];

            return;
        }
        while ($pending !== []) {
            $relative = array_pop($pending);
            $directory = $relative === '.' ? $root : rtrim($root, '/').'/'.$relative;
            if (realpath($directory) !== $directory) {
                continue;
            }
            if (file_exists($directory.'/.git') && ($status = RepositoryStatus::read($directory)) !== null) {
                $repositories[$relative] = $status;
            }
            $level = $relative === '.' ? 0 : substr_count($relative, '/') + 1;
            if ($depth === null || $level < $depth) {
                $lastLevel = $depth === $level + 1;
                $entries = @scandir($directory, SCANDIR_SORT_NONE) ?: [];
                // Pop folders in the same alphabetical depth-first order shown by the tree.
                rsort($entries, SORT_STRING);
                foreach ($entries as $name) {
                    $path = $directory.'/'.$name;
                    if (in_array($name, self::EXCLUDED, true) || ($lastLevel && ! file_exists($path.'/.git'))) {
                        continue;
                    }
                    if (is_dir($path) && ! is_link($path)) {
                        $pending[] = $relative === '.' ? $name : $relative.'/'.$name;
                    }
                }
            }
            if ($deadline !== null && hrtime(true) >= $deadline) {
                break;
            }
        }

    }
}
