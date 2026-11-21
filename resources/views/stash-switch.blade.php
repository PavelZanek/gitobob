<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>Stash and switch · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
@php($query = ['repo' => $relative, 'changed' => request()->boolean('changed') ?: null])
<header><strong>GitoBob</strong><a href="{{ route('repository', $query) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
<main class="branch-page">
    <section class="heading"><div><h1>Stash and switch</h1><p class="repository-meta">{{ $name }} · {{ $branch }} → {{ $target }}</p></div></section>
    <form class="panel" method="post" action="{{ route('repository.stash.save', $query) }}">
        @csrf
        <input type="hidden" name="version" value="{{ $version }}">
        <input type="hidden" name="target" value="{{ $target }}">
        <div class="toolbar"><h2>Changes to stash</h2></div>
        @forelse ($files as $file)
            <div class="branch-row"><span>{{ $file->path }}</span><span class="repository-meta">{{ implode(' · ', array_keys($file->sections)) }}</span></div>
        @empty
            <p class="clean-workspace">No local changes.</p>
        @endforelse
        @if ($files)
            <div class="write-form branch-create">
                @if (collect($files)->contains(fn ($file) => isset($file->sections['Untracked'])))
                    <label><input class="stash-option" type="checkbox" name="untracked" value="1"> Include untracked files</label>
                @endif
                <label for="stash-note">Note (optional)</label>
                <input id="stash-note" name="note" maxlength="512" autocomplete="off">
                <button>Stash and switch to {{ $target }}</button>
            </div>
        @endif
    </form>
</main>
@include('loading')
</body>
</html>
