<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>Push commits · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
<header><strong>GitoBob</strong><a href="{{ route('repository', ['repo' => $relative]) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
<main class="repository-review">
    <section class="heading">
        <div><h1>Push commits</h1><p class="repository-meta">{{ $name }} · {{ $branch }}</p></div>
        <a class="button-link" href="{{ route('repository.push.preview', ['repo' => $relative]) }}">Refresh</a>
    </section>
    @if (session('notice') && session('notice') !== $message)<p class="action-notice" role="status">{{ session('notice') }}</p>@endif
    <section class="panel" aria-label="Push preview">
        <div class="toolbar"><h2>{{ $target ? $remote.'/'.$target : 'Push destination' }}</h2></div>
        @if ($destination)<p class="cloud-row cloud-refs">{{ $destination }}</p>@endif
        @if ($message)
            <p class="clean-workspace" role="status">{{ $message }}</p>
        @else
            <div class="toolbar"><h2>{{ $count }} outgoing {{ Str::plural('commit', $count) }}</h2></div>
            @foreach ($commits as $commit)<div class="cloud-row"><code>{{ $commit }}</code></div>@endforeach
            @if ($count > count($commits))<p class="cloud-row">Showing the newest {{ count($commits) }} commits.</p>@endif
            <form class="write-form" method="post" action="{{ route('repository.push', ['repo' => $relative]) }}">
                @csrf
                <input type="hidden" name="version" value="{{ $version }}">
                <button>Push to {{ $remote }}/{{ $target }}</button>
            </form>
        @endif
    </section>
</main>
@include('loading')
</body>
</html>
