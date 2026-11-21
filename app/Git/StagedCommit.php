<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final class StagedCommit
{
    /**
     * @param  array<string,string>  $environment
     * @return array{branch:string, version:string, summary:string}
     */
    public function preview(string $directory, array $environment = []): array
    {
        $git = Process::path($directory)->env($environment);
        $branch = trim($git->run(['git', 'symbolic-ref', '--quiet', '--short', 'HEAD'])->output()) ?: 'Detached HEAD';
        $head = trim($git->run(['git', 'rev-parse', '--verify', 'HEAD'])->output());
        $raw = $git->run(['git', 'diff', '--cached', '--raw', '--no-abbrev', '--no-renames', '-z'])->throw()->output();
        $summary = $git->run(['git', 'diff', '--cached', '--stat', '--stat-width=100', '--no-color'])->throw()->output();

        return ['branch' => $branch, 'summary' => $summary, 'version' => hash('sha256', serialize([$directory, $head, $branch, $raw]))];
    }

    public function create(string $directory, string $version, string $message): bool
    {
        $index = rtrim(Process::path($directory)->run(['git', 'rev-parse', '--path-format=absolute', '--git-path', 'index'])->throw()->output(), "\n");
        if (! is_file($index)) {
            return false;
        }
        $snapshot = tempnam(dirname($index), 'gitobob-index-');
        if ($snapshot === false) {
            throw new RuntimeException('Could not prepare the staged snapshot.');
        }
        try {
            if (! copy($index, $snapshot)) {
                throw new RuntimeException('Could not read the Git index.');
            }
            $environment = ['GIT_INDEX_FILE' => $snapshot, 'GIT_TERMINAL_PROMPT' => '0'];
            $review = $this->preview($directory, $environment);
            if ($review['summary'] === '' || ! hash_equals($review['version'], $version)) {
                return false;
            }

            Process::path($directory)->env($environment)->input($message)->run(['git', 'commit', '--file=-'])->throw();

            return true;
        } finally {
            @unlink($snapshot);
        }
    }
}
