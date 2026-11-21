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
    @php($comparison = ['repo' => $relative, 'review' => $version])
    <section class="heading">
        <div><h1>{{ $commit ? 'Commit details' : 'Branch sync' }}</h1><p class="repository-meta">{{ $name }} · {{ $branch }}</p></div>
        @if ($commit)
            <a class="button-link" href="{{ route('repository.push.preview', $comparison) }}">Back to branch sync</a>
        @else
            <a class="button-link" href="{{ route('repository.push.preview', ['repo' => $relative]) }}">Refresh</a>
        @endif
    </section>
    @if (session('notice') && session('notice') !== $message)<p class="action-notice" role="status">{{ session('notice') }}</p>@endif
    @if (! $commit)
    <section class="panel" aria-label="Branch comparison">
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
                        @foreach ($history['subjects'] as $oid => $subject)
                            <div class="cloud-row"><a href="{{ route('repository.push.preview', $comparison + ['commit' => $oid]) }}">{{ $subject }}</a></div>
                        @endforeach
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
    @endif
    @if ($detail !== null)
        <section class="panel sync-detail" aria-label="Changed files">
            <div class="toolbar"><div><h2>{{ $detailTitle }}</h2><span class="repository-meta">{{ count($detail['files']) }} changed {{ Str::plural('file', count($detail['files'])) }} · {{ $commit ? (isset($local['subjects'][$commit]) ? 'Local only' : 'Remote only') : substr($head, 0, 8).' → '.substr($tip, 0, 8) }}</span></div></div>
            @if ($detail['files'])
                <div class="review-columns">
                    <nav class="review-files" aria-label="Select a changed file">
                        <ul class="files">
                            @foreach ($detail['files'] as $id => $file)
                                <li><a href="{{ route('repository.push.preview', $comparison + ['commit' => $commit ?: null, 'file' => $id]) }}#sync-diff" @if ($detail['selected'] === $file) aria-current="true" @endif>
                                    <span class="file-label"><span class="filename">{{ $file->path }}</span>
                                    @if ($file->originalPath !== null)<span class="file-origin">From {{ $file->originalPath }}</span>@endif</span>
                                    <span class="file-states">{{ $file->description('Sync') }}</span>
                                </a></li>
                            @endforeach
                        </ul>
                    </nav>
                    <section id="sync-diff" class="review-diff" aria-label="File diff">
                        @if ($detail['selected'])
                            <div class="toolbar"><h2 class="filename">{{ $detail['selected']->path }}</h2><label class="wrap-control"><input type="checkbox" id="wrap-lines">Wrap lines</label></div>
                            @include('patch', ['patch' => $detail['patch'], 'section' => 'Sync'])
                        @else
                            <p class="clean-workspace">Select a file to see its diff.</p>
                        @endif
                    </section>
                </div>
            @else
                <p class="clean-workspace">No files changed in this comparison.</p>
            @endif
        </section>
    @endif
</main>
@include('loading')
</body>
</html>
