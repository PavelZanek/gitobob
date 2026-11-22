<!doctype html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ $commit ? __('Commit details') : ($path ? __('File history') : __('History')) }} · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
<header><strong>GitoBob</strong><a href="{{ route('repository', ['repo' => $relative]) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">{{ __('Settings') }}</a></header>
<main class="repository-review">
    @php($query = ['repo' => $relative, 'tip' => $tip, 'path' => $path !== '' ? $path : null] + request()->only('comparison', 'return_file', 'return_page'))
    <section class="heading">
        <div><h1>{{ $commit ? __('Commit details') : ($path !== '' ? __('File history') : __('History')) }}</h1>
            <p class="repository-meta">{{ $name }}@if ($path !== '') · {{ $path }}@elseif ($tip) {{ __('· History through') }} {{ substr($tip, 0, 8) }}@endif</p>
        </div>
        @if ($commit && request()->filled('comparison'))
            <a class="button-link" href="{{ route('repository.compare', ['repo' => $relative, 'comparison' => request()->query('comparison'), 'tab' => 'commits', 'file' => request()->query('return_file'), 'page' => request()->integer('return_page', 1)]) }}">{{ __('Back to comparison') }}</a>
        @elseif ($commit)<a class="button-link" href="{{ route('repository.history', $query + ['page' => $page]) }}">{{ $path !== '' ? __('Back to file history') : __('Back to history') }}</a>@endif
    </section>
    @if ($detail !== null)
        <section class="panel sync-detail" aria-label="{{ __('Changed files') }}">
            <div class="toolbar"><div><h2>{{ $summary['subject'] ?: __('(No subject)') }}</h2>
                <span class="repository-meta">{{ $summary['short'] }} · {{ $summary['author'] }} · {{ $summary['date'] }} · {{ trans_choice('counts.changed_files', count($detail['files'])) }}</span>
            </div></div>
            @include('revision-files', ['detailRoute' => 'repository.history', 'detailQuery' => $query + ['commit' => $commit, 'page' => $page], 'fileHistoryTip' => $commit])
        </section>
    @else
        <section class="panel" aria-label="{{ __('Commits') }}">
            <div class="toolbar"><h2>{{ __('Commits') }}</h2></div>
            <ul class="files history-list">
                @forelse ($commits as $entry)
                    <li><a data-prefetch href="{{ route('repository.history', $query + ['commit' => $entry['oid'], 'page' => $page, 'file' => $entry['path'] !== null ? hash('sha256', $entry['path']) : null]) }}">
                        <span class="file-label"><strong>{{ $entry['subject'] ?: __('(No subject)') }}</strong><span class="file-dir">{{ $entry['author'] }} · {{ $entry['date'] }}</span></span>
                        <code>{{ $entry['short'] }}</code>
                    </a></li>
                @empty
                    <li class="clean-workspace">{{ $path !== '' ? __('No commits found for this file.') : __('No commits on this page.') }}</li>
                @endforelse
            </ul>
            @if ($page > 1 || $older)
                <nav class="toolbar" aria-label="{{ __('History pages') }}">
                    @if ($page > 1)<a class="button-link" href="{{ route('repository.history', $query + ['page' => $page - 1]) }}">{{ __('Newer commits') }}</a>@endif
                    @if ($older)<a class="button-link" href="{{ route('repository.history', $query + ['page' => $page + 1]) }}">{{ __('Load older commits') }}</a>@endif
                </nav>
            @endif
        </section>
    @endif
</main>
@include('loading')
</body>
</html>
