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
    @php($return = request()->only('comparison', 'file_back', 'tab', 'page'))
    @php($comparison = ['repo' => $relative, 'review' => $version] + $return)
    <section class="heading">
        <div><h1>{{ $commit ? 'Commit details' : 'Branch sync' }}</h1><p class="repository-meta">{{ $name }} · {{ $branch }}</p></div>
        @if (isset($return['comparison']))<a class="button-link" href="{{ route('repository.compare', ['repo' => $relative, 'file' => $return['file_back'] ?? null] + $return) }}">Back to comparison</a>@endif
        @if ($commit)
            <a class="button-link" href="{{ route('repository.push.preview', $comparison) }}">Back to branch sync</a>
        @else
            <a class="button-link" href="{{ route('repository.push.preview', ['repo' => $relative, 'remote' => $remote ?: null] + $return) }}">Refresh</a>
        @endif
    </section>
    @if (session('notice') && session('notice') !== $message)<p class="action-notice" role="status">{{ session('notice') }}</p>@endif
    @if (session('openComparison'))
        <form method="post" action="{{ route('repository.publish.open', ['repo' => $relative]) }}">
            @csrf
            <input type="hidden" name="key" value="{{ session('openComparison') }}">
            <button>Open pull request on GitHub…</button>
        </form>
    @endif
    @if (! $commit)
    <section class="panel" aria-label="Branch comparison">
        <div class="toolbar"><h2>{{ $target ? $remote.'/'.$target : 'Push destination' }}</h2></div>
        @if (count($remotes) > 1)
            <form class="cloud-row publish-remote" method="get" action="{{ route('repository.push.preview') }}">
                <input type="hidden" name="repo" value="{{ $relative }}">
                @foreach ($return as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
                <label for="publish-remote">Remote</label>
                <select id="publish-remote" name="remote">@foreach ($remotes as $option)<option @selected($option === $remote)>{{ $option }}</option>@endforeach</select>
                <button>Preview destination</button>
            </form>
        @endif
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
                        @foreach ($history['subjects'] as $oid => $subject)
                            <div class="cloud-row"><a href="{{ route('repository.push.preview', $comparison + ['commit' => $oid]) }}">{{ $subject }}</a></div>
                        @endforeach
                        @if ($history['count'] > count($history['subjects']))<p class="cloud-row">Showing the newest {{ count($history['subjects']) }} commits.</p>@endif
                    </section>
                @endforeach
            </div>
        @endif
        @if ($canFetch || $canPush || $canPublish)
            <div class="sync-actions">
                @foreach (array_filter(['publish' => $canPublish ? 'Publish branch' : null, 'fetch' => $canFetch ? 'Fetch' : null, 'push' => $canPush ? 'Push to '.$remote.'/'.$target : null, 'pull' => $relation === 'behind' ? 'Pull (fast-forward)' : null]) as $action => $label)
                    <form method="post" action="{{ route('repository.'.$action, ['repo' => $relative] + $return) }}">
                        @if ($action === 'publish')<input type="hidden" name="remote" value="{{ $remote }}">@endif
                        @csrf
                        <input type="hidden" name="version" value="{{ $version }}">
                        <button>{{ $label }}</button>
                    </form>
                @endforeach
            </div>
        @endif
    </section>
    @endif
    @if ($detail !== null)
        <section class="panel sync-detail" aria-label="Changed files">
            <div class="toolbar"><div><h2>{{ $detailTitle }}</h2><span class="repository-meta">{{ count($detail['files']) }} changed {{ Str::plural('file', count($detail['files'])) }} · {{ $commit ? (isset($local['subjects'][$commit]) ? 'Local only' : 'Remote only') : substr($head, 0, 8).' → '.substr($tip, 0, 8) }}</span></div></div>
            @include('revision-files', ['detailRoute' => 'repository.push.preview', 'detailQuery' => $comparison + ['commit' => $commit ?: null], 'fileHistoryTip' => $commit ?: $tip])
        </section>
    @endif
</main>
@include('loading')
</body>
</html>
