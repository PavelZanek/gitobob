<?php

namespace App\Git;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * @phpstan-type Preview array{branch:string, remote:string, target:string, destination:string, head:string, message:string, version:string, commits:list<string>, count:int}
 */
final class BranchPush
{
    /** @param list<string> $arguments */
    private function git(string $directory, array $arguments): ProcessResult
    {
        return Process::path($directory)->timeout(25)->env(['GIT_TERMINAL_PROMPT' => '0', 'LC_ALL' => 'C'])
            ->run(['git', ...$arguments]);
    }

    /** @return Preview */
    public function preview(string $directory): array
    {
        $branch = trim($this->git($directory, ['symbolic-ref', '--quiet', 'HEAD'])->output());
        $head = trim($this->git($directory, ['rev-parse', '--verify', 'HEAD'])->output());
        $review = ['branch' => str_starts_with($branch, 'refs/heads/') ? substr($branch, 11) : 'Detached HEAD',
            'remote' => '', 'target' => '', 'destination' => '', 'head' => $head, 'message' => '',
            'version' => '', 'commits' => [], 'count' => 0];
        if ($branch === '' || $head === '') {
            return ['message' => 'Check out a branch with a commit to push.'] + $review;
        }

        $tracking = trim($this->git($directory, ['for-each-ref', '--format=%(upstream:remotename)%09%(upstream:remoteref)', '--', $branch])->output());
        [$remote, $target] = array_pad(explode("\t", $tracking, 2), 2, '');
        if ($remote === '' || $remote === '.' || ! str_starts_with($target, 'refs/heads/')) {
            return ['message' => 'Set an upstream branch in Git to enable push.'] + $review;
        }

        $review['remote'] = $remote;
        $review['target'] = substr($target, 11);
        if (in_array($review['target'], ['main', 'staging'], true)) {
            return ['message' => 'This destination is protected. Use a pull request to update it.'] + $review;
        }

        $urls = trim($this->git($directory, ['remote', 'get-url', '--push', '--all', '--', $remote])->output());
        if ($urls === '' || str_contains($urls, "\n") || trim($this->git($directory, ['config', '--bool', '--get', 'remote.'.$remote.'.mirror'])->output()) === 'true') {
            return ['message' => 'Push requires a single, non-mirrored destination.'] + $review;
        }

        $review['destination'] = $this->displayDestination($urls);
        try {
            $tip = $this->git($directory, ['ls-remote', '--refs', '--', $urls, $target]);
        } catch (ProcessTimedOutException) {
            return ['message' => 'The remote timed out. Check your connection and refresh.'] + $review;
        }
        if (! $tip->successful()) {
            return ['message' => 'Could not read the remote. Check your connection and Git credentials, then refresh.'] + $review;
        }

        $upstream = explode("\t", trim($tip->output()))[0];
        if ($upstream === '') {
            return ['message' => 'The upstream branch no longer exists. Check its tracking settings.'] + $review;
        }
        if ($upstream === $head) {
            return ['message' => 'Up to date'] + $review;
        }
        if (! $this->git($directory, ['merge-base', '--is-ancestor', $upstream, $head])->successful()) {
            return ['message' => 'The remote has commits outside your local history. Fetch and integrate them before pushing.'] + $review;
        }

        $range = $upstream.'..'.$head;
        $review['count'] = (int) $this->git($directory, ['rev-list', '--count', $range])->throw()->output();
        $log = $this->git($directory, ['log', '--no-decorate', '--format=%h %s', '-100', $range])->throw()->output();
        $review['commits'] = explode("\n", rtrim($log, "\n"));
        $review['version'] = hash('sha256', serialize([$directory, $branch, $head, $remote, $urls, $target, $upstream]));

        return $review;
    }

    private function displayDestination(string $url): string
    {
        if (str_contains($url, '://')) {
            return parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH);
        }

        return preg_replace('/^[^\/@:]+@/', '', $url) ?? '';
    }

    public function send(string $directory, string $version): string
    {
        $review = $this->preview($directory);
        if ($review['message'] === 'Up to date') {
            return 'Up to date';
        }
        if (! hash_equals($review['version'], $version) || $review['version'] === '') {
            return 'Push stopped. The branch or destination changed since your preview.';
        }

        try {
            $result = $this->git($directory, ['push', '--porcelain', '--no-follow-tags', '--recurse-submodules=no',
                '--', $review['remote'], $review['head'].':refs/heads/'.$review['target']]);
        } catch (ProcessTimedOutException) {
            return 'Push timed out. Refresh to check whether the remote received your commits.';
        }

        return $result->successful()
            ? 'Pushed '.substr($review['head'], 0, 8).' to '.$review['remote'].'/'.$review['target'].'.'
            : 'Git rejected the push. Check the remote branch, permissions and pre-push hooks.';
    }
}
