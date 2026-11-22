<?php

namespace App\Git;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class FileDiff
{
    private const int MAX_BYTES = 262144;

    public function preview(string $directory, ChangedFile $file, string $section): string
    {
        $command = [
            'git', '--no-pager', '--no-optional-locks', '--literal-pathspecs', '-c', 'core.fsmonitor=false',
            '--git-dir=.git', '--work-tree=.', 'diff', '--no-color', '--no-ext-diff', '--no-textconv',
            '--submodule=short', '--src-prefix=a/', '--dst-prefix=b/', '--unified=3',
        ];

        if ($section === 'Untracked') {
            $path = $directory.'/'.$file->path;
            if (realpath(dirname($path)) !== dirname($path) || (! is_link($path) && ! is_file($path))) {
                return 'This file is no longer available.';
            }
            array_push($command, '--no-index', '--', '/dev/null', $file->path === '-' ? './-' : $file->path);
        } else {
            if ($section === 'Staged') {
                $command[] = '--cached';
            }
            array_push($command, '--', $file->path);
            if ($file->originalPath !== null && in_array($file->sections[$section], ['R', 'C'], true)) {
                $command[] = $file->originalPath;
            }
        }

        return $this->read($command, $directory, $section === 'Untracked');
    }

    public function between(string $directory, string $base, string $tip, ChangedFile $file): string
    {
        $paths = $file->originalPath === null ? [$file->path] : [$file->originalPath, $file->path];

        return $this->read(['git', '--literal-pathspecs', 'diff', '--no-color', '--no-ext-diff', '--no-textconv',
            '--submodule=short', '--find-renames', $base, $tip, '--', ...$paths], $directory, false);
    }

    public function staged(string $directory): string
    {
        return $this->read(['git', 'diff', '--cached', '--no-color', '--no-ext-diff', '--no-textconv'], $directory, false);
    }

    /** @param list<string> $command */
    private function read(array $command, string $directory, bool $noIndex): string
    {
        $process = new Process($command, $directory);
        $preview = '';
        try {
            $process->start();
            foreach ($process as $stream => $chunk) {
                if ($stream !== Process::OUT) {
                    continue;
                }
                if (strlen($preview) + strlen($chunk) > self::MAX_BYTES) {
                    $process->stop(0);

                    return $preview.substr($chunk, 0, self::MAX_BYTES - strlen($preview))."\n… Preview limited to 256 KiB.";
                }
                $preview .= $chunk;
            }
        } catch (ProcessTimedOutException) {
            return 'Git took too long to generate this diff.';
        }

        if (! $process->isSuccessful() && ! ($noIndex && $process->getExitCode() === 1)) {
            return 'Git could not read this diff. Refresh the repository to check its current state.';
        }

        return $preview === '' ? 'No text changes to display.' : $preview;
    }
}
