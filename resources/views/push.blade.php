<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>Branch sync · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
<header><strong>GitoBob</strong><a href="{{ route('repository', ['repo' => $relative]) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
<main class="repository-review">
    <section class="heading">
        <div><h1>Branch sync</h1><p class="repository-meta">{{ $name }} · {{ $branch }}</p></div>
        <a class="button-link" href="{{ route('repository.push.preview', ['repo' => $relative]) }}">Refresh</a>
    </section>
    @if (session('notice') && session('notice') !== $message)<p class="action-notice" role="status">{{ session('notice') }}</p>@endif
    <section class="panel" aria-label="Push preview">
        <div class="toolbar"><h2>{{ $target ? $remote.'/'.$target : 'Push destination' }}</h2></div>
        @if ($destination)<p class="cloud-row cloud-refs">{{ $destination }}</p>@endif
        @if ($message)
            <p class="clean-workspace" role="status">{{ $message }}</p>
        @endif
        @if ($relation)
            <div class="sync-comparison">
                @foreach ([['Local only', $branch, $local, 'outgoing'], ['Remote only', $remote.'/'.$target, $incoming, 'incoming']] as [$label, $ref, $history, $direction])
                    <section aria-label="{{ $label }}">
                        <div class="toolbar"><div><h2>{{ $label }}</h2><span class="repository-meta">{{ $ref }}</span></div></div>
                        <p class="cloud-row">{{ $history['count'] }} {{ $direction }} {{ Str::plural('commit', $history['count']) }}</p>
                        @foreach ($history['subjects'] as $subject)<div class="cloud-row"><code>{{ $subject }}</code></div>@endforeach
                        @if ($history['count'] > count($history['subjects']))<p class="cloud-row">Showing the newest {{ count($history['subjects']) }} commits.</p>@endif
                    </section>
                @endforeach
            </div>
        @endif
        @if ($canFetch || $canPush)
            <div class="sync-actions">
                @foreach (array_filter(['fetch' => $canFetch ? 'Fetch' : null, 'push' => $canPush ? 'Push to '.$remote.'/'.$target : null, 'pull' => $relation === 'behind' ? 'Pull (fast-forward)' : null]) as $action => $label)
                    <form method="post" action="{{ route('repository.'.$action, ['repo' => $relative]) }}">
                        @csrf
                        <input type="hidden" name="version" value="{{ $version }}">
                        <button>{{ $label }}</button>
                    </form>
                @endforeach
            </div>
        @endif
    </section>
</main>
@include('loading')
</body>
</html>
