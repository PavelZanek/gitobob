<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;

final class ConflictReview
{
    private const int PREVIEW_LIMIT = 262144;

    /** @return array<int, string> */
    private static function record(ChangedFile $file): array
    {
        return explode(' ', $file->statusRecord, 11);
    }

    public static function identity(ChangedFile $file): string
    {
        $record = self::record($file);
        unset($record[6]); // The working file mode may change in the editor.

        return hash('sha256', serialize($record));
    }

    private function blob(string $directory, string $mode, string $oid): string
    {
        if ($mode === '000000') {
            return 'Deleted on this side.';
        }
        if ($mode === '160000') {
            return 'Submodule commit '.$oid;
        }
        $git = Process::path($directory);
        if ((int) $git->run(['git', 'cat-file', '-s', $oid])->throw()->output() > self::PREVIEW_LIMIT) {
            return 'Preview exceeds 256 KiB.';
        }

        return $this->text($git->run(['git', 'cat-file', 'blob', $oid])->throw()->output());
    }

    private function text(string $content): string
    {
        if (str_contains($content, "\0") || ! mb_check_encoding($content, 'UTF-8')) {
            return 'Binary or non-UTF-8 content.';
        }

        return $content === '' ? '(Empty file)' : $content;
    }

    /** @return array{content:string, editable:bool, markers:bool, version:?string} */
    public function working(string $directory, ChangedFile $file, string $branch): array
    {
        $path = $directory.'/'.$file->path;
        $editable = $markers = false;
        $fingerprint = null;
        $parent = dirname($path);
        while (! file_exists($parent) && ! is_link($parent)) {
            $parent = dirname($parent);
        }
        if (realpath($parent) !== $parent || is_link($path) || (file_exists($path) && ! is_file($path))) {
            $content = 'Review this file type in Git.';
        } elseif (! file_exists($path)) {
            $content = 'Deleted in the working tree. Marking this file as resolved will stage its deletion.';
            $fingerprint = 'deleted';
        } else {
            $bytes = file_get_contents($path, false, null, 0, self::PREVIEW_LIMIT + 1);
            $large = $bytes !== false && strlen($bytes) > self::PREVIEW_LIMIT;
            $content = match (true) {
                $bytes === false => 'Could not read the working file.',
                $large => 'Preview exceeds 256 KiB.',
                default => $this->text($bytes),
            };
            if ($bytes !== false) {
                $editable = ! str_contains($bytes, "\0") && mb_check_encoding($large ? mb_strcut($bytes, 0, self::PREVIEW_LIMIT, 'UTF-8') : $bytes, 'UTF-8');
                $markers = $editable && preg_match('/^(<{7,}|>{7,})(?: |\r?$)/m', $bytes) === 1;
                $digest = $large ? hash_file('sha256', $path) : hash('sha256', $bytes);
                $fingerprint = $digest === false ? null : [$digest, fileperms($path)];
            }
        }

        $version = $fingerprint === null ? null : hash('sha256', serialize([$directory, $branch, $file->statusRecord, $fingerprint]));

        return ['content' => $content, 'editable' => $editable, 'markers' => $markers, 'version' => $version];
    }

    /** @return array{current:string, incoming:string} */
    public function sides(string $directory, ChangedFile $file): array
    {
        $record = self::record($file);

        return ['current' => $this->blob($directory, $record[4], $record[8]), 'incoming' => $this->blob($directory, $record[5], $record[9])];
    }

    public function openEditor(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return Process::run(['/usr/bin/open', '-t', $path])->successful();
        }
        if (PHP_OS_FAMILY === 'Windows') {
            Process::start(['notepad.exe', $path]);

            return true;
        }
        $editor = Process::run(['xdg-mime', 'query', 'default', 'text/plain']);
        $desktop = trim($editor->output());

        return $editor->successful() && $desktop !== '' && Process::run(['gtk-launch', '--', $desktop, $path])->successful();
    }

    public function resolve(string $directory, string $id, string $version): bool
    {
        $status = RepositoryStatus::read($directory);
        $file = $status?->conflicts()[$id] ?? null;
        if ($status === null || $file === null) {
            return false;
        }
        $working = $this->working($directory, $file, $status->branch);
        if ($working['version'] !== $version || $working['markers']) {
            return false;
        }

        return Process::path($directory)->run(['git', '--literal-pathspecs', 'add', '-A', '--', $file->path])->successful();
    }
}
