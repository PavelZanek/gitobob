<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $name }} · GitoBob</title>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
</head>
<body>
    <header><strong>GitoBob</strong><a href="{{ route('workspace', $onlyChanged ? ['changed' => 1] : []) }}">Workspace</a></header>
    @php($query = ['repo' => $relative, 'changed' => $onlyChanged ?: null])
    @php($selection = $query + ['file' => $fileId, 'diff' => $showDiff ? null : 'hidden'])
    <main @class(['repository-review' => $showDiff && $selected])>
        <section class="heading">
            <div><h1>{{ $name }}</h1><p class="path">{{ $status->branch }} · {{ $status->changedFiles }} {{ $status->changedFiles === 1 ? 'changed file' : 'changed files' }}</p></div>
            <a href="{{ route('repository', $selection) }}">Refresh</a>
        </section>
        <section class="panel" aria-label="Changed files">
            <div class="toolbar">
                <h2>Changes</h2>
                <div class="review-actions">
                    @if ($status->changedFiles > 0)
                        <a href="{{ route('repository', $query + ['file' => $selected ? $fileId : null, 'diff' => $showDiff ? 'hidden' : null]) }}">{{ $showDiff ? 'Hide diff' : 'Show diff' }}</a>
                    @endif
                    <nav class="layout-picker" aria-label="File list layout">
                        @foreach (['all' => 'All files', 'grouped' => 'Group by status'] as $layout => $label)
                            <a href="{{ route('repository', $selection + ['layout' => $layout]) }}"
                               @if ($grouped === ($layout === 'grouped')) aria-current="true" @endif>{{ $label }}</a>
                        @endforeach
                    </nav>
                </div>
            </div>
            <div @class(['review-columns' => $showDiff && $selected])>
                <div class="review-files">
                    @if ($status->changedFiles === 0)
                        <p class="clean-workspace">This repository is clean.</p>
                    @endif
                    @foreach ($groups as $section => $files)
                        @if ($grouped)<h3 class="section-title">{{ $section }}</h3>@endif
                        <ul class="files">
                            @foreach ($files as $id => $file)
                                <li>
                                    <a href="{{ route('repository', $query + ['file' => $id]) }}#diff{{ $grouped ? '-'.strtolower($section) : '' }}"
                                       @if ($id === $fileId) aria-current="true" @endif>
                                        <span class="filename">@if ($file->originalPath !== null){{ $file->originalPath }} → @endif{{ $file->path }}</span>
                                        <span class="file-states">{{ $grouped ? $file->description($section) : implode(' · ', array_keys($file->sections)) }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endforeach
                </div>
                @if ($showDiff && $fileId !== null)
                    <section id="diff" class="review-diff" aria-label="File diff">
                        @if ($selected)
                            <div class="toolbar"><h2 class="filename">{{ $selected->path }}</h2></div>
                            @foreach ($previews as $section => $patch)
                                <h3 id="diff-{{ strtolower($section) }}" class="section-title">{{ $section }}</h3>
                                <pre class="patch" tabindex="0" aria-label="{{ $section }} diff">@foreach (explode("\n", rtrim($patch, "\n")) as $line)<span @class(['addition' => str_starts_with($line, '+'), 'deletion' => str_starts_with($line, '-'), 'hunk' => str_starts_with($line, '@@')])>{{ $line }}</span>{{ "\n" }}@endforeach</pre>
                            @endforeach
                        @else
                            <p class="clean-workspace">This file is no longer in the change list.</p>
                        @endif
                    </section>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
