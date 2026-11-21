<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;

/**
 * @phpstan-type Branch array{name:string, oid:string, remote:string, target:string, local:bool}
 * @phpstan-type Pair array{base:Branch, head:Branch, ancestor:string}
 */
final class BranchComparison
{
    /** @return array{refs:array<string, Branch>, current:string} */
    public function branches(string $directory): array
    {
        $git = Process::path($directory);
        $remotes = array_filter(explode("\n", trim($git->run(['git', 'remote'])->throw()->output())));
        usort($remotes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $format = '%(refname)%09%(objectname)%09%(symref)%09%(upstream:remotename)%09%(upstream:remoteref)%09%(HEAD)';
        $rows = $git->run(['git', 'for-each-ref', '--format='.$format, 'refs/heads/', 'refs/remotes/'])->throw()->output();
        $refs = [];
        $current = '';
        foreach (explode("\n", rtrim($rows, "\n")) as $line) {
            if ($line === '') {
                continue;
            }
            [$ref, $oid, $symbolic, $remote, $upstream, $checked] = explode("\t", $line);
            if ($symbolic !== '') {
                continue;
            }
            $local = str_starts_with($ref, 'refs/heads/');
            $name = explode('/', $ref, 3)[2];
            $target = str_starts_with($upstream, 'refs/heads/') ? substr($upstream, 11) : $name;
            if (! $local) {
                foreach ($remotes as $candidate) {
                    if (str_starts_with($name, $candidate.'/')) {
                        $remote = $candidate;
                        $target = substr($name, strlen($remote) + 1);
                        break;
                    }
                }
            } elseif ($remote === '') {
                $remote = in_array('origin', $remotes, true) ? 'origin' : (count($remotes) === 1 ? $remotes[0] : '');
            }
            $refs[$ref] = compact('name', 'oid', 'remote', 'target', 'local');
            if ($checked === '*') {
                $current = $ref;
            }
        }

        return ['refs' => $refs, 'current' => $current];
    }

    /**
     * @param  Branch  $base
     * @param  Branch  $head
     * @return Pair
     */
    public function pair(string $directory, array $base, array $head): array
    {
        $ancestor = trim(Process::path($directory)->run(['git', 'merge-base', $base['oid'], $head['oid']])->output());

        return compact('base', 'head', 'ancestor');
    }

    /** @param Branch $branch */
    private function slug(string $directory, array $branch, bool $push): ?string
    {
        if ($branch['remote'] === '' || $branch['remote'] === '.') {
            return null;
        }
        $urls = Process::path($directory)->run(['git', 'remote', 'get-url', ...($push ? ['--push'] : []), '--all', '--', $branch['remote']]);

        $url = trim($urls->output());

        return $urls->successful() && ! str_contains($url, "\n") ? GitHubClient::slug($url) : null;
    }

    /**
     * @param  Pair  $pair
     * @return array{label:string, message:string, url?:string, sync?:bool, repository?:string, source?:string, number?:int}
     */
    public function action(string $directory, array $pair, GitHubClient $github): array
    {
        $head = $pair['head'];
        $base = $pair['base'];
        $repo = $this->slug($directory, $base, false);
        $source = $this->slug($directory, $head, true);
        if ($repo === null || $source === null) {
            return ['label' => '', 'message' => 'A GitHub remote is needed to open a pull request.'];
        }
        if (strcasecmp($source, $repo) === 0 && $head['target'] === $base['target']) {
            return ['label' => '', 'message' => 'Both branches point to the same GitHub branch.'];
        }

        $live = $this->branches($directory);
        $current = 'refs/heads/'.$head['name'];
        if (($live['refs'][$current] ?? null) !== $head ||
            ! in_array($base, $live['refs'], true)) {
            return ['label' => '', 'message' => 'The branches changed. Compare again to update this review.'];
        }
        $number = $github->matchingPull($repo, $base['target'], $source, $head['target']);
        $published = $github->branchTip($source, $head['target']);
        if ($published !== $head['oid']) {
            if ($live['current'] !== $current) {
                return ['label' => '', 'message' => 'Switch to '.$head['name'].' to sync this branch.'];
            }
            $ahead = $published === null || Process::path($directory)->run(['git', 'merge-base', '--is-ancestor', $published, $head['oid']])->successful();
            $push = $ahead && ! in_array($head['target'], ['main', 'staging'], true);

            return ['label' => $push ? 'Push…' : 'Sync…', 'sync' => true, 'message' => $push ? 'Publish your local commits before opening the pull request.' : 'Review the branch and its remote before opening the pull request.'];
        }
        if ($number !== null) {
            return ['label' => 'Open pull request', 'message' => '#'.$number, 'number' => $number, 'repository' => $repo, 'url' => 'https://github.com/'.$repo.'/pull/'.$number];
        }
        if ($github->branchTip($repo, $base['target']) !== $base['oid']) {
            return ['label' => '', 'message' => 'The base on GitHub differs from this comparison. Sync the base branch and compare again.'];
        }
        $prefix = strcasecmp($source, $repo) === 0 ? '' : str_replace('/', ':', $source).':';

        return ['label' => 'Create pull request…', 'repository' => $repo, 'source' => $source, 'message' => '', 'url' => 'https://github.com/'.$repo.'/compare/'.rawurlencode($base['target']).'...'.$prefix.rawurlencode($head['target']).'?expand=1'];
    }
}
