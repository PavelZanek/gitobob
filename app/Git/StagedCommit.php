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

    public function create(string $directory, string $version, string $message): ?string
    {
        $index = rtrim(Process::path($directory)->run(['git', 'rev-parse', '--path-format=absolute', '--git-path', 'index'])->throw()->output(), "\n");
        if (! is_file($index)) {
            return null;
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
                return null;
            }

            $result = Process::path($directory)->env($environment)->input($message)->run([
                'git', '-c', 'core.abbrev=no', '-c', 'color.ui=false', 'commit', '--file=-',
            ])->throw();
            if (preg_match('/^\[[^\r\n]* ([0-9a-f]{40}|[0-9a-f]{64})\]/m', $result->output(), $match)) {
                return $match[1];
            }

            return trim(Process::path($directory)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
        } finally {
            @unlink($snapshot);
        }
    }
}
