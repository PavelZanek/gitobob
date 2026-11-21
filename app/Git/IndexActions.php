<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;

final class IndexActions
{
    public static function version(string $directory, ChangedFile $file, string $branch): ?string
    {
        $path = $directory.'/'.$file->path;
        if (in_array('U', $file->sections, true) || is_dir($path) && ! is_link($path)) {
            return null;
        }
        $parent = realpath(dirname($path));
        if ($parent !== false && $parent !== dirname($path)) {
            return null;
        }
        $content = match (true) {
            is_link($path) => readlink($path),
            is_file($path) => hash_file('sha256', $path),
            file_exists($path) => false,
            default => 'absent',
        };

        return $content === false ? null : hash('sha256', serialize([$directory, $branch, $file, $content]));
    }

    public function change(string $directory, string $id, string $section, string $version, ?int $hunk = null, ?string $patchVersion = null): string
    {
        $status = RepositoryStatus::read($directory);
        $file = $status?->files[$id] ?? null;
        if ($file === null || ! isset($file->sections[$section]) || self::version($directory, $file, $status->branch) !== $version) {
            return 'The file changed. Review the current diff and try again.';
        }
        if ($hunk !== null) {
            $patch = (new FileDiff)->preview($directory, $file, $section);
            $parts = preg_split('/(?=^@@ -\d)/m', $patch);
            $headers = array_shift($parts);
            if ($file->sections[$section] !== 'M' || ! isset($parts[$hunk]) || hash('sha256', $patch) !== $patchVersion
                || str_contains($patch, "\n… Preview limited to 256 KiB.") || ! preg_match('/^--- [^\n]+\n\+\+\+ [^\n]+\n\z/m', $headers, $paths)) {
                return 'The change is no longer available. Review the current diff.';
            }
            $args = ['git', 'apply', '--cached', '--whitespace=nowarn'];
            if ($section === 'Staged') {
                $args[] = '--reverse';
            }
            $result = Process::path($directory)->input($paths[0].$parts[$hunk])->run([...$args, '-']);

            return $result->successful() ? ($section === 'Staged' ? 'Change unstaged.' : 'Change staged.')
                : 'Git could not apply this change. Review the current diff.';
        }
        $args = $section === 'Staged' ? ['reset', '-q'] : ['add', '-A'];
        $paths = [$file->path];
        if ($file->originalPath !== null && $file->sections[$section] === 'R') {
            $paths[] = $file->originalPath;
        }
        $result = Process::path($directory)->run(['git', '--literal-pathspecs', ...$args, '--', ...$paths]);
        if (! $result->successful()) {
            return 'Git could not update the index. Check the repository and try again.';
        }

        return $section === 'Staged' ? 'File unstaged.' : 'File staged.';
    }
}
