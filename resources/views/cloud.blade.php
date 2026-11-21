<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ $repository ?? 'GitHub repositories' }} · GitoBob</title>
</head>
<body>
    <header><strong>GitoBob</strong><span>GitHub</span>@include('modes')</header>
    <main data-cloud-url="{{ route('cloud.data', ['repository' => $repository]) }}">
        <section class="heading">
            <h1>{{ $repository ?? 'GitHub repositories' }}</h1>
            <div class="actions">
                @if ($repository)<a class="button-link" href="{{ route('cloud') }}">Choose repository</a>@endif
                <button type="button" data-cloud-refresh>Refresh</button>
            </div>
        </section>
        @if ($noRemote)<p class="cloud-notice">This local project has no GitHub remote. Choose a repository below.</p>@endif
        <p class="cloud-notice" data-cloud-status role="status">Updating from GitHub…</p>
        @if ($repository === null)
            <label class="cloud-filter">Find a repository
                <input type="search" data-cloud-filter placeholder="Owner or repository name">
            </label>
        @endif
        <div data-cloud-content>
            @if ($snapshot)
                @include('cloud-data')
            @endif
        </div>
    </main>
    @include('loading')
</body>
</html>
