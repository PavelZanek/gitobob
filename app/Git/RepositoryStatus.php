<?php

namespace App\Git;

final readonly class RepositoryStatus
{
    private function __construct(public string $branch, public int $changedFiles) {}

    public static function fromPorcelain(string $output): self
    {
        $branch = 'Detached HEAD';
        $paths = [];
        $records = explode("\0", $output);
        $length = count($records);

        for ($i = 0; $i < $length; $i++) {
            $record = $records[$i];
            if (str_starts_with($record, '# branch.head ')) {
                $head = substr($record, strlen('# branch.head '));
                $branch = $head === '(detached)' ? 'Detached HEAD' : $head;

                continue;
            }

            // Porcelain v2 gives each record type a fixed number of fields before the path.
            $fields = match ($record[0] ?? '') {
                '1' => 9, '2' => 10, 'u' => 11, '?' => 2, default => null,
            };
            if ($fields === null) {
                continue;
            }

            $parts = explode(' ', $record, $fields);
            $path = end($parts);
            // With -uall a trailing slash denotes an embedded untracked repository.
            if (! str_ends_with($path, '/')) {
                $paths[$path] = true;
            }

            if ($record[0] === '2') {
                $i++; // A rename or copy has a second NUL-delimited path.
            }
        }

        return new self($branch, count($paths));
    }
}
