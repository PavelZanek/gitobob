<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>Commit staged changes · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
<header><strong>GitoBob</strong><a href="{{ route('repository', ['repo' => $relative]) }}">{{ $name }}</a></header>
<main class="repository-review">
    <section class="heading"><div><h1>Commit staged changes</h1><p class="repository-meta">{{ $relative === '.' ? $name : $relative }} · {{ $branch }}</p></div></section>
    @if (session('notice'))<p class="action-notice" role="status">{{ session('notice') }}</p>@endif
    <section class="panel">
        @if ($summary === '')
            <p class="clean-workspace">No staged changes to commit.</p>
        @else
            <div class="toolbar"><h2>Staged files</h2></div>
            <pre class="commit-summary">{{ $summary }}</pre>
            <form class="write-form" method="post" action="{{ route('repository.commit', ['repo' => $relative]) }}">
                @csrf
                <input type="hidden" name="version" value="{{ $version }}">
                <label for="commit-message">Commit message</label>
                <textarea id="commit-message" name="message" rows="3" required maxlength="16000">{{ old('message') }}</textarea>
                @error('message')<p role="alert">{{ $message }}</p>@enderror
                <button>Create local commit</button>
            </form>
            <div class="toolbar"><h2>Staged diff</h2></div>
            @include('patch', ['section' => 'Staged'])
        @endif
    </section>
</main>
@include('loading')
</body>
</html>
