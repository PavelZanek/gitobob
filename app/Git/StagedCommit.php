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

    /** @return array{branch:string, version:string, summary:string, original:string, head:string, indexVersion:string} */
    public function amendPreview(string $directory, BranchPush $push, bool $fetch): array
    {
        $remote = $push->preview($directory);
        if (! $remote['canFetch']) {
            throw new RuntimeException('Amend requires a reachable upstream branch.');
        }
        if ($fetch) {
            $result = $push->fetch($directory, $remote['version']);
            if (! $result['ok']) {
                throw new RuntimeException($result['notice']);
            }
            $remote = $push->preview($directory);
        }
        $git = Process::path($directory);
        $head = $remote['head'];
        $tracked = trim($git->run(['git', 'rev-parse', '--verify', $remote['tracking']])->output());
        $shared = $git->run(['git', 'for-each-ref', '--count=1', '--contains='.$head, '--format=%(refname)', 'refs/remotes']);
        if (! $remote['canFetch'] || $remote['relation'] !== 'ahead' || $tracked !== $remote['tip'] || ! $shared->successful() || trim($shared->output()) !== '') {
            throw new RuntimeException('Amend is available only for an unpublished tip ahead of its verified upstream.');
        }
        $gitDir = trim($git->run(['git', 'rev-parse', '--absolute-git-dir'])->throw()->output());
        foreach (['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'sequencer'] as $marker) {
            if (file_exists($gitDir.'/'.$marker)) {
                throw new RuntimeException('Finish the current Git operation before amending.');
            }
        }
        $preview = $this->preview($directory);
        $original = rtrim($git->run(['git', 'log', '-1', '--format=%B', $head])->throw()->output(), "\n");

        return ['version' => hash('sha256', $remote['version'].$preview['version']), 'head' => $head, 'original' => $original, 'indexVersion' => $preview['version']] + $preview;
    }

    public function create(string $directory, string $version, string $message, ?string $amendHead = null): ?string
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
            if (($amendHead === null && $review['summary'] === '')
                || ($amendHead !== null && trim(Process::path($directory)->run(['git', 'rev-parse', 'HEAD'])->output()) !== $amendHead)
                || ! hash_equals($review['version'], $version)) {
                return null;
            }

            $result = Process::path($directory)->env($environment)->input($message)->run([
                'git', '-c', 'core.abbrev=no', '-c', 'color.ui=false', 'commit', ...($amendHead === null ? [] : ['--amend', '--allow-empty']), '--file=-',
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
