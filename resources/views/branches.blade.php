<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>Local branches · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
@php($query = ['repo' => $relative, 'changed' => request()->boolean('changed') ?: null])
<header><strong>GitoBob</strong><a href="{{ route('repository', $query) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
<main class="branch-page">
    <section class="heading"><div><h1>Local branches</h1><p class="repository-meta">{{ $name }} · {{ $current }}</p></div></section>
    @if (session('notice'))<p class="action-notice branch-message" role="status">{{ session('notice') }}</p>@endif
    @if (session('blockedBranch'))
        <a class="button-link" href="{{ route('repository.stash.preview', $query + ['target' => session('blockedBranch')]) }}">Stash and switch…</a>
    @endif
    @if ($errors->any())<p class="action-notice" role="alert">{{ $errors->first() }}</p>@endif
    <section class="panel">
        <div class="toolbar branch-tools">
            <input type="search" data-branch-search aria-label="Search local branches" placeholder="Search branches…">
            <a class="button-link" href="#create-branch">Create branch…</a>
        </div>
        <form method="post" action="{{ route('repository.branches.change', $query) }}">
            @csrf
            <input type="hidden" name="operation" value="switch">
            <input type="hidden" name="version" value="{{ $version }}">
            @foreach ($branches as $branch)
                <div class="branch-row" data-branch-name="{{ $branch }}"><span>{{ $branch }}</span>
                    @if ($branch === $current)<span class="repository-meta">Current branch</span>
                    @else<button name="branch" value="{{ $branch }}" aria-label="Switch to {{ $branch }}">Switch</button>@endif
                </div>
            @endforeach
            <p class="clean-workspace" data-no-branches @if ($branches) hidden @endif>No matching branches.</p>
        </form>
    </section>
    <section class="panel" id="create-branch">
        <div class="toolbar"><h2>Create branch</h2></div>
        <form class="write-form branch-create" method="post" action="{{ route('repository.branches.change', $query) }}">
            @csrf
            <input type="hidden" name="operation" value="create">
            <input type="hidden" name="version" value="{{ $version }}">
            <label for="new-branch">New branch name</label>
            <input id="new-branch" name="branch" required autocomplete="off" spellcheck="false">
            <p class="repository-meta">Starts at {{ $current }} and keeps your staged and unstaged changes.</p>
            <button>Create and switch</button>
        </form>
    </section>
</main>
@include('loading')
</body>
</html>
