@php($amend = $amend ?? false)
<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ $amend ? 'Amend last commit' : 'Commit staged changes' }} · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
<header><strong>GitoBob</strong><a href="{{ route('repository', ['repo' => $relative]) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
<main class="repository-review">
    <section class="heading"><div><h1>{{ $amend ? 'Amend last commit' : 'Commit staged changes' }}</h1><p class="repository-meta">{{ $relative === '.' ? $name : $relative }} · {{ $branch }}</p></div></section>
    @if (session('notice'))<p class="action-notice" role="status">{{ session('notice') }}</p>@endif
    <section class="panel">
        @if ($summary === '' && ! $amend)
            <p class="clean-workspace">No staged changes to commit.</p>
        @else
            @if ($amend)<p class="cloud-row">Editing local commit <code>{{ substr($head, 0, 8) }}</code> · Upstream verified</p>@endif
            @if ($summary !== '')<div class="toolbar"><h2>Staged files</h2></div>
            <pre class="commit-summary">{{ $summary }}</pre>@else<p class="cloud-row">Only the commit message will change.</p>@endif
            <form class="write-form" method="post" action="{{ route($amend ? 'repository.amend' : 'repository.commit', ['repo' => $relative]) }}">
                @csrf
                <input type="hidden" name="version" value="{{ $version }}">
                <label for="commit-message">Commit message</label>
                <textarea id="commit-message" name="message" rows="3" required maxlength="16000">{{ old('message', $original ?? '') }}</textarea>
                @error('message')<p role="alert">{{ $message }}</p>@enderror
                <button>{{ $amend ? 'Amend local commit' : 'Create local commit' }}</button>
            </form>
            @if ($summary !== '')<div class="toolbar"><h2>Staged diff</h2></div>
            @include('patch', ['section' => 'Staged'])@endif
        @endif
    </section>
</main>
@include('loading')
</body>
</html>
