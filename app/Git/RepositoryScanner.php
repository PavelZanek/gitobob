<?php

namespace App\Git;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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
        $key = 'workspace-tree-v1:'.hash('sha256', $root);
        if ($reuse) {
            $snapshot = Cache::get($key);
            if (is_array($snapshot)) {
                return $snapshot['tree'];
            }
        }

        $tree = $this->scan($root);
        Cache::put($key, ['tree' => $tree], now()->addMinutes(5));

        return $tree;
    }

    public function forgetWorkspace(): void
    {
        $root = Storage::disk('local')->get('workspace.path');
        if (is_string($root)) {
            Cache::forget('workspace-tree-v1:'.hash('sha256', $root));
        }
    }

    public function scan(string $directory): ?RepositoryFolder
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            return null;
        }

        $status = in_array('.git', $entries, true) ? RepositoryStatus::read($directory) : null;

        $children = [];
        foreach ($entries as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            if (in_array($name, self::EXCLUDED, true) || is_link($path) || ! is_dir($path)) {
                continue;
            }

            $child = $this->scan($path);
            if ($child !== null) {
                $children[] = $child;
            }
        }

        return $status !== null || $children !== []
            ? new RepositoryFolder(basename($directory) ?: '/', $status, $children)
            : null;
    }
}
