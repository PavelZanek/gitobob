<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;

final readonly class RepositoryStatus
{
    public int $changedFiles;

    /** @param array<string, ChangedFile> $files */
    private function __construct(public string $branch, public array $files)
    {
        $this->changedFiles = count($files);
    }

    /** @return array<string, array<string, ChangedFile>> */
    public function groups(bool $grouped): array
    {
        if (! $grouped) {
            return $this->files === [] ? [] : ['All files' => $this->files];
        }

        $groups = array_fill_keys(['Staged', 'Unstaged', 'Untracked'], []);
        foreach ($this->files as $id => $file) {
            foreach (array_keys($file->sections) as $section) {
                $groups[$section][$id] = $file;
            }
        }

        return array_filter($groups);
    }

    /** @return array<string, ChangedFile> */
    public function conflicts(): array
    {
        return array_filter($this->files, static fn (ChangedFile $file): bool => ($file->sections['Unstaged'] ?? null) === 'U');
    }

    public static function read(string $directory): ?self
    {
        $result = Process::path($directory)->run([
            'git', '--no-optional-locks', '-c', 'core.fsmonitor=false', '--git-dir=.git', '--work-tree=.',
            'status', '--porcelain=v2', '-z', '--branch', '--no-ahead-behind',
            '--untracked-files=all', '--ignore-submodules=dirty',
        ]);

        return $result->successful() ? self::fromPorcelain($result->output()) : null;
    }

    public static function fromPorcelain(string $output): self
    {
        $branch = 'Detached HEAD';
        $files = [];
        $records = explode("\0", $output);
        $length = count($records);

        for ($i = 0; $i < $length; $i++) {
            $record = $records[$i];
            if (str_starts_with($record, '# branch.head ')) {
                $head = substr($record, strlen('# branch.head '));
                $branch = $head === '(detached)' ? 'Detached HEAD' : $head;

                continue;
            }

            $type = $record[0] ?? '';
            $fields = match ($type) {
                '1' => 9, '2' => 10, 'u' => 11, '?' => 2, default => null,
            };
            if ($fields === null) {
                continue;
            }

            $parts = explode(' ', $record, $fields);
            $path = end($parts);
            // With -uall a trailing slash denotes an embedded untracked repository.
            if (str_ends_with($path, '/')) {
                continue;
            }

            $id = hash('sha256', $path);
            $file = $files[$id] ??= new ChangedFile($path);
            $file->statusRecord = $record;
            if ($type === '?') {
                $file->sections['Untracked'] = '?';
            } elseif ($type === 'u') {
                $file->sections['Unstaged'] = 'U';
            } else {
                [$index, $worktree] = str_split($parts[1]);
                if ($index !== '.') {
                    $file->sections['Staged'] = $index;
                }
                if ($worktree !== '.') {
                    $file->sections['Unstaged'] = $worktree;
                }
            }

            if ($type === '2') {
                $file->originalPath = $records[++$i];
            }
        }

        uasort($files, static fn (ChangedFile $a, ChangedFile $b): int => strcmp($a->path, $b->path));

        return new self($branch, $files);
    }
}
