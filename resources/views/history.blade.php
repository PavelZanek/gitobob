<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ $commit ? 'Commit details' : ($path ? 'File history' : 'History') }} · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
<header><strong>GitoBob</strong><a href="{{ route('repository', ['repo' => $relative]) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
<main class="repository-review">
    @php($query = ['repo' => $relative, 'tip' => $tip, 'path' => $path !== '' ? $path : null])
    <section class="heading">
        <div><h1>{{ $commit ? 'Commit details' : ($path !== '' ? 'File history' : 'History') }}</h1>
            <p class="repository-meta">{{ $name }}@if ($path !== '') · {{ $path }}@elseif ($tip) · History through {{ substr($tip, 0, 8) }}@endif</p>
        </div>
        @if ($commit)<a class="button-link" href="{{ route('repository.history', $query + ['page' => $page]) }}">{{ $path !== '' ? 'Back to file history' : 'Back to history' }}</a>@endif
    </section>
    @if ($detail !== null)
        <section class="panel sync-detail" aria-label="Changed files">
            <div class="toolbar"><div><h2>{{ $summary['subject'] ?: '(No subject)' }}</h2>
                <span class="repository-meta">{{ $summary['short'] }} · {{ $summary['author'] }} · {{ $summary['date'] }} · {{ count($detail['files']) }} changed {{ Str::plural('file', count($detail['files'])) }}</span>
            </div></div>
            @include('revision-files', ['detailRoute' => 'repository.history', 'detailQuery' => $query + ['commit' => $commit, 'page' => $page], 'fileHistoryTip' => $commit])
        </section>
    @else
        <section class="panel" aria-label="Commits">
            <div class="toolbar"><h2>Commits</h2></div>
            <ul class="files history-list">
                @forelse ($commits as $entry)
                    <li><a data-prefetch href="{{ route('repository.history', $query + ['commit' => $entry['oid'], 'page' => $page, 'file' => $entry['path'] !== null ? hash('sha256', $entry['path']) : null]) }}">
                        <span class="file-label"><strong>{{ $entry['subject'] ?: '(No subject)' }}</strong><span class="file-dir">{{ $entry['author'] }} · {{ $entry['date'] }}</span></span>
                        <code>{{ $entry['short'] }}</code>
                    </a></li>
                @empty
                    <li class="clean-workspace">{{ $path !== '' ? 'No commits found for this file.' : 'No commits on this page.' }}</li>
                @endforelse
            </ul>
            @if ($page > 1 || $older)
                <nav class="toolbar" aria-label="History pages">
                    @if ($page > 1)<a class="button-link" href="{{ route('repository.history', $query + ['page' => $page - 1]) }}">Newer commits</a>@endif
                    @if ($older)<a class="button-link" href="{{ route('repository.history', $query + ['page' => $page + 1]) }}">Load older commits</a>@endif
                </nav>
            @endif
        </section>
    @endif
</main>
@include('loading')
</body>
</html>
