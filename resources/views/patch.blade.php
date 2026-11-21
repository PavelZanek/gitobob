@php
    $body = rtrim($patch, "\n");
    $start = strpos($body, "\n@@ ");
    $hasHeaders = $start !== false && ! str_starts_with($body, '@@ ');
    if ($hasHeaders) {
        $headers = substr($body, 0, $start);
        $body = substr($body, $start + 1);
    }
    $old = $new = null;
    $hunkIndex = -1;
@endphp
@if ($hasHeaders)
    <details class="patch-headers"><summary>Patch headers</summary><pre>{{ $headers }}</pre></details>
@endif
<div class="patch" tabindex="0" aria-label="{{ $section }} diff">
    <div class="patch-lines">
        @foreach (explode("\n", $body) as $line)
            @php
                $kind = 'meta';
                $before = $after = '';
                if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $match)) {
                    $old = (int) $match[1];
                    $new = (int) $match[2];
                    $kind = 'hunk';
                    $hunkIndex++;
                } elseif ($old !== null && str_starts_with($line, '+')) {
                    $kind = 'addition';
                    $after = $new++;
                } elseif ($old !== null && str_starts_with($line, '-')) {
                    $kind = 'deletion';
                    $before = $old++;
                } elseif ($old !== null && str_starts_with($line, ' ')) {
                    $kind = 'context';
                    $before = $old++;
                    $after = $new++;
                }
            @endphp
            <div class="patch-row {{ $kind }}"><span class="line-number" aria-hidden="true">{{ $before }}</span><span class="line-number" aria-hidden="true">{{ $after }}</span><code>{{ $line }}@if ($kind === 'hunk' && ($hunkAction ?? false))<button class="hunk-action" form="index-{{ $section }}" name="hunk" value="{{ $hunkIndex }}">{{ $section === 'Staged' ? 'Unstage change' : 'Stage change' }}</button>@endif</code></div>
        @endforeach
    </div>
</div>
