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

        $repository = false;
        if (in_array('.git', $entries, true)) {
            // Pin the working tree so an invalid marker cannot resolve to a parent repository.
            $result = Process::run([
                'git', '--no-optional-locks', '-c', 'core.fsmonitor=false',
                '--git-dir='.$directory.'/.git', '--work-tree='.$directory,
                'status', '--porcelain=v2', '--untracked-files=no', '--ignore-submodules=all',
            ]);
            $repository = $result->successful();
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

        return $repository || $children !== []
            ? new RepositoryFolder(basename($directory) ?: '/', $repository, $children)
            : null;
    }
}
