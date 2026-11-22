<?php

namespace App\Git;

final class SelectedLines
{
    public static function available(string $patch, string $status): bool
    {
        $start = strpos($patch, "\n@@ ");
        if ($start === false || ! in_array($status, ['M', 'A', 'D', '?'], true) || str_contains($patch, "\n… Preview limited to 256 KiB.")) {
            return false;
        }
        $headers = substr($patch, 0, $start);

        return ! preg_match('/^(?:index .* |(?:.*mode) )(?:120000|160000)$/m', $headers);
    }

    /**
     * Pair adjacent removed and added rows so a replacement stays in its original
     * position even when a neighbouring replacement is left out of the selection.
     *
     * @param  list<string>  $lines
     * @return array<int, string> Rows keyed by their original displayed offset.
     */
    private static function orderedRows(array $lines, int $start, bool $reverse): array
    {
        $rows = [];
        $last = null;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '\\') && $last !== null) {
                $rows[$last] .= "\n".$line;
            } else {
                $last = $start + $index;
                $rows[$last] = $line;
            }
        }
        $ordered = $removed = $added = [];
        // A sentinel flushes the final run of changes.
        foreach ($rows + [-1 => ' '] as $index => $line) {
            $prefix = $line[0] ?? '';
            if ($prefix === '-' || $prefix === '+') {
                if (($prefix === '-') !== $reverse) {
                    $removed[] = $index;
                } else {
                    $added[] = $index;
                }

                continue;
            }
            for ($i = 0, $count = max(count($removed), count($added)); $i < $count; $i++) {
                foreach ([$removed[$i] ?? null, $added[$i] ?? null] as $key) {
                    if ($key !== null) {
                        $ordered[$key] = $rows[$key];
                    }
                }
            }
            $removed = $added = [];
            if ($index !== -1) {
                $ordered[$index] = $line;
            }
        }

        return $ordered;
    }

    /**
     * Keep unselected source lines as context and omit unselected additions.
     * Hunk coordinates refer to the index as it will look after preceding selected changes.
     *
     * @param  list<int>  $selection  Zero-based rows in the displayed patch body, including hunk headings.
     */
    public static function patch(string $patch, array $selection, bool $reverse): ?string
    {
        $start = strpos($patch, "\n@@ ");
        if ($selection === [] || $start === false || ! preg_match('/^--- ([^\n]+)\n\+\+\+ ([^\n]+)$/m', substr($patch, 0, $start), $paths)) {
            return null;
        }
        [$source, $target] = $reverse ? [$paths[2], $paths[1]] : [$paths[1], $paths[2]];
        $wanted = array_fill_keys($selection, true);
        $offset = 0;
        $shift = 0;
        $all = true;
        $sourceEnded = $targetEnded = false;
        $result = '';
        $body = rtrim(substr($patch, $start + 1), "\n");
        foreach (preg_split('/(?=^@@ -\d)/m', $body, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $lines = explode("\n", rtrim($part, "\n"));
            $header = array_shift($lines);
            if (! preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $header, $match)) {
                return null;
            }
            $base = $reverse ? 3 : 1;
            $sourceIsEmpty = ($match[$base + 1] ?? '') === '0';
            $position = (int) $match[$base] - (int) ! $sourceIsEmpty;
            $oldCount = $newCount = 0;
            $changed = false;
            $content = '';
            $rows = self::orderedRows($lines, $offset + 1, $reverse);
            $offset += count($lines) + 1;
            foreach ($rows as $row => $line) {
                $kind = $line[0] ?? '';
                if (! in_array($kind, [' ', '+', '-'], true)) {
                    return null;
                }
                if ($reverse && $kind !== ' ') {
                    $kind = $kind === '+' ? '-' : '+';
                }
                $keep = true;
                if ($kind !== ' ' && isset($wanted[$row])) {
                    $changed = true;
                    unset($wanted[$row]);
                } elseif ($kind !== ' ') {
                    $all = false;
                    $keep = $kind === '-';
                    $kind = ' ';
                }
                if ($keep) {
                    // A retained unterminated line cannot be followed by another line on the same side.
                    if (($kind !== '+' && $sourceEnded) || ($kind !== '-' && $targetEnded)) {
                        return null;
                    }
                    if (str_contains($line, "\n")) {
                        $sourceEnded = $sourceEnded || $kind !== '+';
                        $targetEnded = $targetEnded || $kind !== '-';
                    }
                    $content .= $kind.substr($line, 1)."\n";
                    $oldCount += (int) ($kind !== '+');
                    $newCount += (int) ($kind !== '-');
                }
            }
            if ($changed) {
                $old = $position + (int) ($oldCount > 0);
                $new = $position + $shift + (int) ($newCount > 0);
                $result .= "@@ -{$old},{$oldCount} +{$new},{$newCount} @@\n".$content;
                $shift += $newCount - $oldCount;
            }
        }
        if ($wanted !== [] || $result === '') {
            return null;
        }
        // For a literal filename "-", git diff --no-index requires the "./-" spelling.
        if ($target === 'b/./-') {
            $target = 'b/-';
        }
        if ($target === '/dev/null' && ! $all) {
            $target = $source;
        }
        $headers = '';
        if ($source === '/dev/null' || $target === '/dev/null') {
            if (! preg_match('/^(?:new|deleted) file mode (100[0-7]{3})$/m', substr($patch, 0, $start), $mode)) {
                return null;
            }
            $fileHeader = strtok($patch, "\n");
            if ($fileHeader === 'diff --git a/./- b/./-') {
                $fileHeader = 'diff --git a/- b/-';
            }
            $headers = $fileHeader."\n".($source === '/dev/null' ? 'new' : 'deleted').' file mode '.$mode[1]."\n";
        }

        return $headers.'--- '.$source."\n+++ ".$target."\n".$result;
    }
}
