<?php

namespace App\Git;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * @phpstan-type Commits array{count:int, subjects:list<string>}
 * @phpstan-type Preview array{branch:string, remote:string, target:string, destination:string, head:string, message:string, version:string, local:Commits, incoming:Commits, relation:string, canFetch:bool, canPush:bool, tracking:string, tip:string}
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
            'version' => '', 'local' => ['count' => 0, 'subjects' => []], 'incoming' => ['count' => 0, 'subjects' => []],
            'relation' => '', 'canFetch' => false, 'canPush' => false, 'tracking' => '', 'tip' => ''];
        if ($branch === '' || $head === '') {
            return ['message' => 'Check out a branch with a commit to push.'] + $review;
        }

        $tracking = trim($this->git($directory, ['for-each-ref', '--format=%(upstream:remotename)%09%(upstream:remoteref)%09%(upstream)', '--', $branch])->output());
        [$remote, $target, $trackingRef] = array_pad(explode("\t", $tracking, 3), 3, '');
        if ($remote === '' || $remote === '.' || ! str_starts_with($target, 'refs/heads/')) {
            return ['message' => 'Set an upstream branch in Git to enable push.'] + $review;
        }

        $review['remote'] = $remote;
        $review['target'] = substr($target, 11);
        $protected = in_array($review['target'], ['main', 'staging'], true);
        $protectedMessage = 'This destination is protected. Use a pull request to update it.';
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
            return ['message' => $protected ? $protectedMessage : 'The upstream branch no longer exists. Check its tracking settings.'] + $review;
        }

        $fetchUrl = trim($this->git($directory, ['remote', 'get-url', '--', $remote])->output());
        $sameBranch = $fetchUrl === $urls;
        if (! $sameBranch && $fetchUrl !== '') {
            try {
                $fetchTip = $this->git($directory, ['ls-remote', '--refs', '--', $fetchUrl, $target]);
                $sameBranch = $fetchTip->successful() && $fetchTip->output() === $tip->output();
            } catch (ProcessTimedOutException) {
                $sameBranch = false;
            }
        }
        $canFetch = $sameBranch && str_starts_with($trackingRef, 'refs/remotes/')
            && ! $this->git($directory, ['symbolic-ref', '--quiet', $trackingRef])->successful();
        $tracked = $canFetch ? trim($this->git($directory, ['rev-parse', '--verify', $trackingRef])->output()) : '';
        $review['tracking'] = $trackingRef;
        $review['tip'] = $upstream;
        $review['canFetch'] = $canFetch;
        $review['version'] = hash('sha256', serialize([$directory, $branch, $head, $remote, $urls, $fetchUrl, $target, $trackingRef, $upstream, $tracked]));
        if ($upstream === $head) {
            return ['message' => 'Up to date'] + $review;
        }

        if ($this->git($directory, ['merge-base', '--is-ancestor', $upstream, $head])->successful()) {
            $review['local'] = $this->commits($directory, $upstream.'..'.$head);

            return ['relation' => 'ahead', 'canPush' => ! $protected, 'message' => $protected ? $protectedMessage : ''] + $review;
        }
        if (! $canFetch) {
            return ['message' => 'The push destination differs from a fetchable tracking branch. Check the remote settings in Git.'] + $review;
        }
        if ($upstream !== $tracked) {
            return ['message' => 'The remote branch has changed. Fetch to compare commits.'] + $review;
        }

        $review['local'] = $this->commits($directory, $upstream.'..'.$head);
        $review['incoming'] = $this->commits($directory, $head.'..'.$upstream);
        $behind = $this->git($directory, ['merge-base', '--is-ancestor', $head, $upstream])->successful();

        return ['relation' => $behind ? 'behind' : 'diverged', 'message' => $behind
            ? 'Your branch is behind. Pull to fast-forward to the remote commit.'
            : 'The branches have diverged. Integrate the two histories before pushing.'] + $review;
    }

    /** @return Commits */
    private function commits(string $directory, string $range): array
    {
        $count = (int) $this->git($directory, ['rev-list', '--count', $range])->throw()->output();
        $log = $count ? $this->git($directory, ['log', '--no-decorate', '--format=%h %s', '-100', $range])->throw()->output() : '';

        return ['count' => $count, 'subjects' => $count ? explode("\n", rtrim($log, "\n")) : []];
    }

    public function fetch(string $directory, string $version): string
    {
        $review = $this->preview($directory);
        if (! $review['canFetch'] || ! hash_equals($review['version'], $version)) {
            return 'Fetch stopped. Refresh and review the current branch and remote.';
        }

        try {
            $result = $this->git($directory, ['fetch', '--no-tags', '--no-prune', '--no-prune-tags', '--no-recurse-submodules',
                '--no-write-fetch-head', '--refmap=', '--', $review['remote'], '+refs/heads/'.$review['target'].':'.$review['tracking']]);
        } catch (ProcessTimedOutException) {
            return 'Fetch timed out. Refresh to check the tracking branch.';
        }

        return $result->successful() ? 'Fetched '.$review['remote'].'/'.$review['target'].'.'
            : 'Fetch failed. Check your connection, remote branch and Git credentials.';
    }

    public function pull(string $directory, string $version): string
    {
        $review = $this->preview($directory);
        if ($review['relation'] !== 'behind' || ! hash_equals($review['version'], $version)
            || trim($this->git($directory, ['symbolic-ref', '--quiet', 'HEAD'])->output()) !== 'refs/heads/'.$review['branch']
            || trim($this->git($directory, ['rev-parse', '--verify', 'HEAD'])->output()) !== $review['head']) {
            return 'Pull stopped. Review the updated branch comparison.';
        }
        $gitDir = trim($this->git($directory, ['rev-parse', '--absolute-git-dir'])->throw()->output());
        foreach (['MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'sequencer'] as $marker) {
            if (file_exists($gitDir.'/'.$marker)) {
                return 'Finish the current Git operation before pulling.';
            }
        }

        try {
            $result = $this->git($directory, ['merge', '--ff-only', '--no-squash', '--no-autostash', '--no-overwrite-ignore', '--', $review['tip']]);
        } catch (ProcessTimedOutException) {
            return 'Pull timed out. Refresh to check the current branch.';
        }

        return $result->successful() ? 'Fast-forwarded '.$review['branch'].' to '.substr($review['tip'], 0, 8).'.'
            : 'Git could not fast-forward. Check for overlapping local changes or a Git operation in progress.';
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
        if (! $review['canPush'] || ! hash_equals($review['version'], $version)) {
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
