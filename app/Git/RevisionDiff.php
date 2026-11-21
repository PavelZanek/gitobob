<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;

final readonly class RevisionDiff
{
    public function __construct(private FileDiff $diff) {}

    public function parentTree(string $directory, string $commit): string
    {
        $parents = Process::path($directory)->run(['git', 'rev-list', '--parents', '-n', '1', $commit])->throw()->output();
        $firstParent = explode(' ', trim($parents))[1] ?? null;

        return $firstParent ?? trim(Process::path($directory)->input('')->run(['git', 'hash-object', '-t', 'tree', '--stdin'])->throw()->output());
    }

    /** @return array{files:array<string, ChangedFile>, selected:?ChangedFile, patch:string} */
    public function review(string $directory, string $base, string $tip, string $fileId): array
    {
        $output = Process::path($directory)->run(['git', 'diff', '--name-status', '-z', '--find-renames', '--no-ext-diff', '--no-textconv', $base, $tip, '--'])->throw()->output();
        $fields = explode("\0", $output);
        $files = [];
        for ($i = 0; $fields[$i] !== '';) {
            $status = $fields[$i++][0];
            $path = $fields[$i++];
            $renamed = in_array($status, ['R', 'C'], true);
            $file = new ChangedFile($renamed ? $fields[$i++] : $path);
            $file->sections['Sync'] = $status;
            $file->originalPath = $renamed ? $path : null;
            $files[hash('sha256', $file->path)] = $file;
        }

        $selected = $files[$fileId === '' ? array_key_first($files) : $fileId] ?? null;

        return ['files' => $files, 'selected' => $selected, 'patch' => $selected === null ? '' : $this->diff->between($directory, $base, $tip, $selected)];
    }
}
