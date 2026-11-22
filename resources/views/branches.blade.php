<!doctype html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ __('Local branches ·') }} {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
@php($query = ['repo' => $relative, 'changed' => request()->boolean('changed') ?: null])
<header><strong>GitoBob</strong><a href="{{ route('repository', $query) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">{{ __('Settings') }}</a></header>
<main class="branch-page">
    <section class="heading"><div><h1>{{ __('Local branches') }}</h1><p class="repository-meta">{{ $name }} · {{ $current }}</p></div></section>
    @if (session('notice'))<p class="action-notice branch-message" role="status">{{ __(session('notice')) }}</p>@endif
    @if (session('blockedBranch'))
        <a class="button-link" href="{{ route('repository.stash.preview', $query + ['target' => session('blockedBranch')]) }}">{{ __('Stash and switch…') }}</a>
    @endif
    @if ($errors->any())<p class="action-notice" role="alert">{{ $errors->first() }}</p>@endif
    <section class="panel">
        <div class="toolbar branch-tools">
            <input type="search" data-branch-search value="{{ request()->string('find') }}" aria-label="{{ __('Search local branches') }}" placeholder="{{ __('Search branches…') }}">
            <a class="button-link" href="#create-branch">{{ __('Create branch…') }}</a>
        </div>
        <form method="post" action="{{ route('repository.branches.change', $query) }}">
            @csrf
            <input type="hidden" name="operation" value="switch">
            <input type="hidden" name="version" value="{{ $version }}">
            @foreach ($branches as $branch)
                <div class="branch-row" data-branch-name="{{ $branch }}" @if (request()->filled('find') && $branch !== (string) request()->string('find')) hidden @endif><span>{{ $branch }}</span>
                    @if ($branch === $current)<span class="repository-meta">{{ __('Current branch') }}</span>
                    @else<button name="branch" value="{{ $branch }}" aria-label="{{ __('Switch to :branch', ['branch' => $branch]) }}">{{ __('Switch') }}</button>@endif
                </div>
            @endforeach
            <p class="clean-workspace" data-no-branches @if ($branches) hidden @endif>{{ __('No matching branches.') }}</p>
        </form>
    </section>
    <section class="panel" id="create-branch">
        <div class="toolbar"><h2>{{ __('Create branch') }}</h2></div>
        <form class="write-form branch-create" method="post" action="{{ route('repository.branches.change', $query) }}">
            @csrf
            <input type="hidden" name="operation" value="create">
            <input type="hidden" name="version" value="{{ $version }}">
            <label for="new-branch">{{ __('New branch name') }}</label>
            <input id="new-branch" name="branch" required autocomplete="off" spellcheck="false">
            <p class="repository-meta">{{ __('Starts at') }} {{ $current }} {{ __('and keeps your staged and unstaged changes.') }}</p>
            <button>{{ __('Create and switch') }}</button>
        </form>
    </section>
</main>
@include('loading')
</body>
</html>
