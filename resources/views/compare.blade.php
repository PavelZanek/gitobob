<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>Compare branches · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
@php
    $query = ['repo' => $relative, 'base' => $base, 'head' => $head, 'comparison' => $snapshot];
    $selection = ['file' => $file ?: null, 'page' => $page];
    $withAction = $pair && $pair['ancestor'] !== '' && $pair['ancestor'] !== $pair['head']['oid'];
@endphp
<header><strong>GitoBob</strong><a href="{{ route('repository', ['repo' => $relative]) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
<main class="repository-review" @if ($withAction) data-cloud-url="{{ route('repository.compare.action', $query + $selection + ['tab' => $tab]) }}" @endif>
    <section class="heading"><div><h1>Compare branches</h1><p class="repository-meta">{{ $name }}</p></div></section>
    @if (session('notice'))<p class="action-notice" role="status">{{ session('notice') }}</p>@endif
    <section class="panel">
        <form class="toolbar publish-remote compare-picker" method="get" action="{{ route('repository.compare') }}">
            <input type="hidden" name="repo" value="{{ $relative }}">
            @foreach (['head' => 'Source branch', 'base' => 'Base branch'] as $key => $label)
                <label for="{{ $key }}">{{ $label }}</label>
                <select id="{{ $key }}" name="{{ $key }}" required>
                    @if (!isset($refs[$$key]))<option value="">Choose branch</option>@endif
                    @foreach ($refs as $ref => $entry)
                        @if ($key === 'base' || $entry['local'])<option value="{{ $ref }}" @selected($$key === $ref)>{{ $entry['name'] }}</option>@endif
                    @endforeach
                </select>
            @endforeach
            <button>Compare</button>
        </form>
        @if ($withAction)
            <div class="toolbar compare-action">
                <div data-cloud-content></div>
                <span data-cloud-status role="status">Checking pull request…</span>
                <button type="button" data-cloud-refresh>Refresh PR status</button>
            </div>
        @endif
    </section>
    @if (! $pair)
        <p class="clean-workspace">Choose two different branches to compare.</p>
    @elseif ($pair['ancestor'] === '')
        <p class="clean-workspace">These branches have no common ancestor.</p>
    @else
        <section class="panel sync-detail">
            <div class="toolbar">
                <nav class="layout-picker" aria-label="Comparison view">
                    @foreach (['files' => 'Files', 'commits' => 'Commits'] as $key => $label)
                        <a href="{{ route('repository.compare', $query + $selection + ['tab' => $key]) }}" @if ($key === $tab) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </nav>
                <span class="repository-meta">@if ($detail){{ count($detail['files']) }} changed {{ Str::plural('file', count($detail['files'])) }} · @endif Merge base {{ substr($pair['ancestor'], 0, 8) }} → {{ substr($pair['head']['oid'], 0, 8) }}</span>
            </div>
            @if ($tab === 'files')
                @include('revision-files', ['detailRoute' => 'repository.compare', 'detailQuery' => $query + ['page' => $page], 'fileHistoryTip' => $pair['head']['oid']])
            @else
                <ul class="files">
                    @forelse ($commits as $entry)
                        <li><a data-prefetch href="{{ route('repository.history', ['repo' => $relative, 'commit' => $entry['oid'], 'comparison' => $snapshot, 'return_file' => $file ?: null, 'return_page' => $page]) }}">
                            <span class="file-label"><strong>{{ $entry['subject'] ?: '(No subject)' }}</strong><span class="file-dir">{{ $entry['author'] }} · {{ $entry['date'] }}</span></span>
                            <code>{{ $entry['short'] }}</code>
                        </a></li>
                    @empty
                        <li class="clean-workspace">No source-only commits on this page.</li>
                    @endforelse
                </ul>
                @if ($older || $page > 1)
                    <nav class="toolbar" aria-label="Commit pages">
                        @if ($page > 1)<a class="button-link" href="{{ route('repository.compare', $query + ['file' => $file ?: null, 'tab' => $tab, 'page' => $page - 1]) }}">Newer commits</a>@endif
                        @if ($older)<a class="button-link" href="{{ route('repository.compare', $query + ['file' => $file ?: null, 'tab' => $tab, 'page' => $page + 1]) }}">Older commits</a>@endif
                    </nav>
                @endif
            @endif
        </section>
    @endif
</main>
@include('loading')
</body>
</html>
