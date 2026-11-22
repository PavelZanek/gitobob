<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>Fetch repositories · GitoBob</title>
</head>
<body>
<header><strong>GitoBob</strong><a href="{{ route('workspace', ['snapshot' => 1]) }}">Workspace</a><a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
<main>
    <section class="heading"><div><h1>Fetch repositories</h1><p class="repository-meta">{{ $name }} · Fetch the tracked branch in each selected project.</p></div></section>
    <form class="panel" data-workspace-fetch data-workspace="{{ $workspace }}" method="post">
        @csrf
        <div class="toolbar"><label><input type="checkbox" data-fetch-all> Select all</label><button disabled data-fetch-submit>Fetch selected</button></div>
        <p class="cloud-row" data-fetch-progress role="status">Select the repositories to fetch.</p>
        @forelse ($repositories as $relative => $repo)
            <div class="fetch-row" data-fetch-url="{{ route('workspace.fetch.run', ['repo' => $relative]) }}">
                <label><input type="checkbox" data-fetch-choice data-branch="{{ $repo->branch }}" data-upstream="{{ $repo->upstream ?? '' }}" @disabled(empty($repo->upstream))> <strong>{{ $relative === '.' ? $name : $relative }}</strong></label>
                <span class="repository-meta">{{ $repo->branch }} · {{ ($repo->upstream ?? '') ?: 'No upstream' }}</span>
                <span data-fetch-result></span>
            </div>
        @empty
            <p class="clean-workspace">No repositories in this workspace.</p>
        @endforelse
    </form>
</main>
@include('loading')
</body>
</html>
