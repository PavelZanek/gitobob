<?php

namespace App\Git;

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
