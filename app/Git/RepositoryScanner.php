<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;

/**
 * Discover working trees independently of the parent repository's ignore rules.
 */
final class RepositoryScanner
{
    private const array EXCLUDED = ['.', '..', '.git', 'vendor', 'node_modules'];

    public function scan(string $directory): ?RepositoryFolder
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            return null;
        }

        $status = null;
        if (in_array('.git', $entries, true)) {
            // Pin the working tree so an invalid marker cannot resolve to a parent repository.
            $result = Process::path($directory)->run([
                'git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
                '--git-dir=.git', '--work-tree=.',
                'status', '--porcelain=v2', '-z', '--branch', '--no-ahead-behind',
                '--untracked-files=all', '--ignore-submodules=dirty',
            ]);
            if ($result->successful()) {
                $status = RepositoryStatus::fromPorcelain($result->output());
            }
        }

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
