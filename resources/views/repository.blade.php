<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <title>{{ $name }} · GitoBob</title>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
</head>
<body class="review-page">
    <header><strong>GitoBob</strong><a href="{{ route('workspace', ['snapshot' => 1, 'changed' => $onlyChanged ?: null]) }}">Workspace</a>@include('modes', ['local' => $allChanges ? null : $relative])</header>
    @php($reviewRoute = $allChanges ? 'changes' : 'repository')
    @php($snapshot = $allChanges ? ['snapshot' => 1] : [])
    @php($query = ['repo' => $relative === '' ? null : $relative, 'changed' => $onlyChanged ?: null])
    @php($selection = $query + ['file' => $fileId, 'diff' => $showDiff ? null : 'hidden'])
    <main class="repository-review" @unless($allChanges)
        data-refresh-url="{{ route($reviewRoute, $selection + ['slot' => $slot ?: null]) }}"
        data-revision="{{ $revision }}" data-file="{{ $fileId }}" @endunless>
        <section class="heading">
            <div>
                <h1>{{ $name }}</h1>
                <p class="repository-meta">
                    @if ($allChanges)
                        {{ count($repositories) }} {{ count($repositories) === 1 ? 'repository' : 'repositories' }} ·
                    @else
                        <small title="Current branch">{{ $repositories[$relative]->branch }}</small>
                    @endif
                    {{ $total }} {{ $total === 1 ? 'changed file' : 'changed files' }}
                </p>
            </div>
            <a class="button-link" href="{{ route($reviewRoute, $selection) }}">Refresh</a>
        </section>
        <section class="panel" aria-label="Changed files">
            <div class="toolbar">
                @if ($allChanges)
                    <nav class="layout-picker" aria-label="Workspace view">
                        <a href="{{ route('workspace', ['snapshot' => 1, 'changed' => $onlyChanged ?: null]) }}">Repositories</a>
                        <a aria-current="page" href="{{ route('changes', ['snapshot' => 1, 'changed' => $onlyChanged ?: null]) }}">All changes</a>
                    </nav>
                @else
                    <h2>Changes</h2>
                @endif
                <div class="review-actions">
                    @if ($total > 0)
                        <a class="button-link" href="{{ route($reviewRoute, $snapshot + $query + ['file' => $selected ? $fileId : null, 'diff' => $showDiff ? 'hidden' : null]) }}">{{ $showDiff ? 'Hide diff' : 'Show diff' }}</a>
                    @endif
                    <nav class="layout-picker" aria-label="File list layout">
                        @foreach (['all' => 'All files', 'grouped' => 'Group by status'] as $layout => $label)
                            <a href="{{ route($reviewRoute, $snapshot + $selection + ['layout' => $layout]) }}"
                               @if ($grouped === ($layout === 'grouped')) aria-current="true" @endif>{{ $label }}</a>
                        @endforeach
                    </nav>
                </div>
            </div>
            <div @class(['review-columns' => $showDiff && $selected])>
                <div class="review-files">
                    @if ($total > 0)
                        <div class="file-filter" hidden>
                            <input type="search" placeholder="Filter files…" aria-label="Filter files by name or path">
                        </div>
                        <p class="no-matches" role="status" hidden>No matching files.</p>
                    @endif
                    @if ($total === 0)
                        <p class="clean-workspace">{{ $allChanges ? 'All repositories are clean.' : 'This repository is clean.' }}</p>
                    @endif
                    @foreach ($groups as $repoPath => $sections)
                        <div class="repository-files">
                            @if ($allChanges)
                                <h3 class="repo-title"><span>{{ $repoPath === '.' ? $rootName : $repoPath }}</span><small title="Current branch">{{ $repositories[$repoPath]->branch }}</small></h3>
                            @endif
                            @foreach ($sections as $section => $files)
                                @if ($grouped)<h3 class="section-title">{{ $section }}</h3>@endif
                                <ul class="files">
                                    @foreach ($files as $id => $file)
                                        <li>
                                            <a href="{{ route($reviewRoute, $snapshot + ['repo' => $repoPath, 'file' => $id, 'changed' => $onlyChanged ?: null]) }}#diff{{ $grouped ? '-'.strtolower($section) : '' }}"
                                               @if ((string) $repoPath === $relative && $id === $fileId) aria-current="true" @endif>
                                                <span class="file-label">
                                                    <span class="filename">{{ basename($file->path) }}</span>
                                                    @if (dirname($file->path) !== '.')<span class="file-dir">{{ dirname($file->path) }}</span>@endif
                                                    @if ($file->originalPath !== null)<span class="file-origin">From {{ $file->originalPath }}</span>@endif
                                                </span>
                                                <span class="file-states">{{ $grouped ? $file->description($section) : implode(' · ', array_keys($file->sections)) }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @if ($showDiff && $fileId !== null)
                    <section id="diff" class="review-diff" aria-label="File diff">
                        @if ($selected)
                            <div class="toolbar">
                                <div>@if ($allChanges)<span class="diff-repository">{{ $relative === '.' ? $rootName : $relative }}</span>@endif<h2 class="filename">{{ $selected->path }}</h2></div>
                                <label class="wrap-control"><input type="checkbox" id="wrap-lines">Wrap lines</label>
                            </div>
                            <div class="pull-controls" data-local-navigation hidden>
                                <nav aria-label="Navigate changed files">
                                    <a class="button-link" role="link" data-step="k" aria-label="Previous file" title="Previous file (K)" aria-keyshortcuts="k">←</a>
                                    <span data-file-position aria-live="polite"></span>
                                    <a class="button-link" role="link" data-step="j" aria-label="Next file" title="Next file (J)" aria-keyshortcuts="j">→</a>
                                </nav>
                                @include('hunk-navigation')
                            </div>
                            @foreach ($previews as $section => $patch)
                                <h3 id="diff-{{ strtolower($section) }}" class="section-title">{{ $section }}</h3>
                                @include('patch')
                            @endforeach
                        @else
                            <p class="clean-workspace">This file is no longer in the change list.</p>
                        @endif
                    </section>
                @endif
            </div>
        </section>
    </main>
    @include('loading')
</body>
</html>
