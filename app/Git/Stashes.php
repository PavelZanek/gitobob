<?php

namespace App\Git;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

final readonly class Stashes
{
    public function __construct(private LocalBranches $branches, private RevisionDiff $revisions, private FileDiff $diff) {}

    /** @param list<string> $args */
    private function git(string $directory, array $args): ProcessResult
    {
        return Process::path($directory)->env(['LC_ALL' => 'C', 'GIT_TERMINAL_PROMPT' => '0'])->run(['git', ...$args]);
    }

    /** @return array<string, string> */
    public function entries(string $directory): array
    {
        $output = $this->git($directory, ['stash', 'list', '--format=%H%x09%gs'])->throw()->output();
        $entries = [];
        foreach (explode("\n", rtrim($output, "\n")) as $row) {
            if ($row !== '') {
                [$oid, $label] = explode("\t", $row, 2);
                $entries[$oid] = $label;
            }
        }

        return $entries;
    }

    /** @return array{branch:string, branchVersion:string, validTarget:bool, files:array<string, ChangedFile>, version:string} */
    public function workspace(string $directory, string $target = ''): array
    {
        $branches = $this->branches->listing($directory);
        $files = RepositoryStatus::read($directory)->files ?? throw new \RuntimeException('Git could not read the repository.');
        $versions = [];
        foreach ($files as $file) {
            $versions[] = [IndexActions::version($directory, $file, $branches['current']), $file->statusRecord];
        }

        return ['branch' => $branches['current'], 'branchVersion' => $branches['version'], 'files' => $files,
            'validTarget' => $branches['current'] !== $target && in_array($target, $branches['branches'], true),
            'version' => hash('sha256', serialize([$branches['version'], $target, $versions]))];
    }

    public function saveAndSwitch(string $directory, string $target, string $version, string $note, bool $untracked): string
    {
        $state = $this->workspace($directory, $target);
        if (! $state['validTarget'] || ! hash_equals($state['version'], $version)) {
            return 'The branch or files changed. Review them before stashing.';
        }
        $save = array_filter($state['files'], static fn (ChangedFile $file): bool => $untracked || ! isset($file->sections['Untracked']));
        if ($save === []) {
            return 'No files selected for stashing.';
        }

        $args = ['stash', 'push', '--no-keep-index'];
        if ($untracked) {
            $args[] = '--include-untracked';
        }
        try {
            $result = $this->git($directory, [...$args, '--message', $note !== '' ? $note : 'Before switching to '.$target]);
        } catch (ProcessTimedOutException) {
            return 'Stashing timed out. Check Stashes and the working tree before retrying.';
        }
        $saved = trim($this->git($directory, ['rev-parse', '--verify', '--quiet', 'refs/stash'])->output());
        if (! $result->successful() || $saved === '' || str_contains($result->output(), 'No local changes to save')) {
            return __('Check Stashes and the working tree before switching.').' '.trim($result->errorOutput());
        }
        $switch = $this->branches->change($directory, $state['branchVersion'], $target, false);

        return __('Saved stash :id.', ['id' => substr($saved, 0, 8)]).' '.__($switch['message']);
    }

    /** @return array{files:array<string, ChangedFile>, selected:?ChangedFile, patches:array<string, string>} */
    public function review(string $directory, string $oid, string $fileId): array
    {
        $ranges = ['Staged' => [$oid.'^1', $oid.'^2'], 'Unstaged' => [$oid.'^2', $oid]];
        if ($this->git($directory, ['rev-parse', '--verify', '--quiet', $oid.'^3'])->successful()) {
            $ranges['Untracked'] = [$this->revisions->parentTree($directory, $oid.'^3'), $oid.'^3'];
        }
        $files = $patches = $sections = [];
        foreach ($ranges as $label => [$base, $tip]) {
            $sections[$label] = $this->revisions->files($directory, $base, $tip);
            $files += $sections[$label];
        }
        $id = $fileId !== '' ? $fileId : array_key_first($files);
        foreach ($ranges as $label => [$base, $tip]) {
            if (isset($sections[$label][$id])) {
                $patches[$label] = $this->diff->between($directory, $base, $tip, $sections[$label][$id]);
            }
        }

        return ['files' => $files, 'selected' => $files[$id] ?? null, 'patches' => $patches];
    }

    public function apply(string $directory, string $oid, string $version): string
    {
        if (! isset($this->entries($directory)[$oid]) || ! hash_equals($this->workspace($directory)['version'], $version)) {
            return 'The stash, branch or files changed. Review them before applying.';
        }

        try {
            $result = $this->git($directory, ['stash', 'apply', '--index', $oid]);
        } catch (ProcessTimedOutException) {
            return 'Apply timed out. Inspect the working tree before retrying. The stash is still saved.';
        }
        if ($result->successful()) {
            return __('Applied stash :id. The stash is still saved.', ['id' => substr($oid, 0, 8)]);
        }
        $conflicts = trim($this->git($directory, ['diff', '--name-only', '--diff-filter=U'])->output());
        $reason = $conflicts === '' ? trim($result->errorOutput()."\n".$result->output()) : "Conflicted files:\n".$conflicts;

        return __('Git could not fully apply this stash. The stash is still saved.')."\n".$reason;
    }
}
