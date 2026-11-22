<?php

namespace App\Git;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final class LocalBranches
{
    /** @param list<string> $arguments */
    private function git(string $directory, array $arguments): ProcessResult
    {
        return Process::path($directory)->env(['LC_ALL' => 'C', 'GIT_TERMINAL_PROMPT' => '0'])->run(['git', ...$arguments]);
    }

    /** @return list<string> */
    public function names(string $directory): array
    {
        $refs = trim($this->git($directory, ['for-each-ref', '--format=%(refname:lstrip=2)', 'refs/heads'])->output());

        return $refs === '' ? [] : explode("\n", $refs);
    }

    /** @return array{current:string, branches:list<string>, version:string} */
    public function listing(string $directory): array
    {
        $current = trim($this->git($directory, ['symbolic-ref', '--quiet', 'HEAD'])->output());
        $head = trim($this->git($directory, ['rev-parse', '--verify', 'HEAD'])->output());
        $refs = trim($this->git($directory, ['for-each-ref', '--sort=refname', '--format=%(refname:lstrip=2)%09%(objectname)', 'refs/heads/'])->throw()->output());
        $branches = [];
        foreach (($refs === '' ? [] : explode("\n", $refs)) as $row) {
            $branches[] = explode("\t", $row, 2)[0];
        }

        return ['current' => $current === '' ? 'Detached HEAD' : substr($current, 11),
            'branches' => $branches, 'version' => hash('sha256', serialize([$directory, $current, $head, $refs]))];
    }

    /** @return array{ok:bool, message:string} */
    public function change(string $directory, string $version, string $name, bool $create): array
    {
        $state = $this->listing($directory);
        if (! hash_equals($state['version'], $version)) {
            return ['ok' => false, 'message' => 'The branches changed. Review the current list and try again.'];
        }
        if (str_starts_with($name, '-') || str_contains($name, "\0") || $name === '@'
            || rtrim($this->git($directory, ['check-ref-format', '--branch', $name])->output(), "\n") !== $name) {
            return ['ok' => false, 'message' => 'Enter a valid Git branch name.'];
        }
        $exists = in_array($name, $state['branches'], true);
        if ($create === $exists) {
            return ['ok' => false, 'message' => $create ? 'A local branch with this name already exists.' : 'This local branch no longer exists.'];
        }

        $args = $create ? ['--no-track', '-c', $name] : ['--', $name];
        try {
            $result = $this->git($directory, ['switch', '--no-guess', '--no-overwrite-ignore', '--no-recurse-submodules', ...$args]);
        } catch (ProcessTimedOutException) {
            return ['ok' => false, 'message' => 'The branch operation timed out. Check the current branch before retrying.'];
        }

        if (! $result->successful()) {
            return ['ok' => false, 'message' => trim($result->errorOutput()) ?: 'Git could not switch branches.'];
        }

        return ['ok' => true, 'message' => __($create ? 'Created and switched to :branch.' : 'Switched to :branch.', ['branch' => $name])];
    }
}
