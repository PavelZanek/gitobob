<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ $repository }} #{{ $number }} · GitoBob</title>
</head>
<body class="review-page">
    <header><strong>GitoBob</strong><a href="{{ route('cloud', ['repository' => $repository]) }}">{{ $repository }}</a>@include('modes')<a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
    @php
        $base = ['number' => $number, 'repository' => $repository];
        $query = $base + ['filter' => $filter !== '' ? $filter : null, 'hide' => $hide ?: null];
        $browse = $query + ['snapshot' => 1];
    @endphp
    <main class="repository-review" @isset($scope)
        data-refresh-url="{{ route('cloud.pull', $query + ['page' => $page, 'file' => $selected['id'] ?? null, 'slot' => $position ?: null]) }}"
        data-refresh-scope="{{ hash('sha256', $scope) }}" data-revision="{{ hash('sha256', $scope.$revision) }}" data-file="{{ $selected['id'] ?? '' }}" @endisset>
        <section class="heading">
            <div>
                <h1>{{ $detail['pull']['title'] ?? 'Pull request' }}</h1>
                <p class="repository-meta">#{{ $number }} @if ($detail) · {{ ($detail['pull']['draft'] ?? false) ? 'Draft' : 'Ready for review' }} · {{ $detail['pull']['head'] }} → {{ $detail['pull']['base'] }} @endif</p>
            </div>
            <div class="actions">
                @if (($detail['pull']['draft'] ?? false) && ($detail['pull']['state'] ?? '') === 'open')
                    <form method="post" data-review-write action="{{ route('cloud.pull.ready', $query + ['page' => $page, 'file' => $selected['id'] ?? null]) }}">
                        @csrf
                        @include('pull-revision')
                        <button>Mark ready for review</button>
                    </form>
                @endif
                <form method="post" action="{{ route('cloud.pull.open', $base) }}">@csrf<button>Open on GitHub</button></form>
                <a class="button-link" href="{{ route('cloud.pull', $query + ['page' => $page, 'file' => $file]) }}">Refresh</a>
            </div>
        </section>
        @if (session('notice'))<p role="status" class="action-notice">{{ session('notice') }}</p>@endif
        @isset($error)
            <p class="empty" role="alert">{{ $error }}</p>
        @else
            <details class="review-summary" data-review-summary="{{ route('cloud.pull.summary', $base + ['head' => $detail['pull']['head_sha'] ?? '']) }}">
                <summary><strong>Checks &amp; reviews</strong> <span data-summary-label role="status">Loading…</span></summary>
                <div data-summary-details></div>
            </details>
            <section class="thread-navigation" aria-label="Review conversations" data-threads
                data-url="{{ route('cloud.pull.threads', $base + ['head' => $detail['pull']['head_sha'] ?? '', 'base' => $detail['pull']['base_sha'] ?? '', 'path' => $selected['filename'] ?? null, 'thread' => request()->query('thread')]) }}"
                data-file="{{ $selected['filename'] ?? '' }}" data-thread="{{ request()->query('thread') }}">
                <span data-thread-count role="status">Loading conversations…</span>
                <a class="button-link" data-next-thread hidden>Next unresolved</a>
                <div data-detached-threads></div>
                <template data-thread-form>
                    <form class="write-form" method="post" data-review-write action="{{ route('cloud.pull.threads.action', $base + ['file' => $selected['id'] ?? null, 'locate' => 1]) }}">
                        @csrf
                        @include('pull-revision')
                        <input type="hidden" name="thread"><input type="hidden" name="submission"><input type="hidden" name="operation">
                        <button type="submit"></button>
                    </form>
                </template>
                @if (old('thread') && old('body') && old('scope') === hash('sha256', $scope))
                    <details open><summary>Unsent reply</summary><p>{{ old('body') }}</p></details>
                @endif
            </section>
            <details class="panel comment-box" data-comment-draft="{{ hash('sha256', $scope) }}" @if(session('posted') === hash('sha256', $scope)) data-posted @endif @if(old('comment') || $errors->has('comment')) open @endif>
                <summary>Comment on pull request</summary>
                <form class="write-form" method="post" action="{{ route('cloud.pull.comment', $query + ['file' => $selected['id'] ?? null, 'page' => $page]) }}">
                    @csrf
                    <input type="hidden" name="scope" value="{{ hash('sha256', $scope) }}">
                    <input type="hidden" name="submission" value="{{ Str::uuid() }}">
                    <label for="pr-comment">Comment on {{ $repository }} #{{ $number }}</label>
                    <textarea id="pr-comment" name="comment" rows="3" required maxlength="65536">{{ old('comment') }}</textarea>
                    @error('comment')<p role="alert">{{ $message }}</p>@enderror
                    <button>Post comment</button>
                </form>
            </details>
            <section class="panel" aria-label="Changed files">
                <div class="toolbar">
                    <h2>Changed files ({{ $count }})</h2>
                    @if ($pages > 1)
                        <nav class="actions" aria-label="File pages">
                            @if ($page > 1)<a href="{{ route('cloud.pull', $browse + ['page' => $page - 1]) }}">Previous</a>@endif
                            <span>Page {{ $page }} of {{ $pages }}</span>
                            @if ($page < $pages)<a href="{{ route('cloud.pull', $browse + ['page' => $page + 1]) }}">Next</a>@endif
                        </nav>
                    @endif
                </div>
                @if ($count > \App\Git\GitHubClient::MAX_PR_FILES)
                    <p class="clean-workspace">GitHub provides the first 3,000 changed files.</p>
                @endif
                @if ($count > 0)
                    <form class="pull-filters" action="{{ route('cloud.pull', ['number' => $number]) }}" data-pull-filter>
                        <input type="hidden" name="repository" value="{{ $repository }}">
                        <input type="hidden" name="snapshot" value="1">
                        <input type="search" name="filter" value="{{ $filter }}" aria-label="Filter changed files" placeholder="Filter by filename or path…" maxlength="1024" autocomplete="off">
                        <label class="wrap-control"><input type="checkbox" name="hide" value="1" @checked($hide)>Hide viewed</label>
                        @if ($filter !== '' || $hide)<span>{{ $total }} {{ Str::plural('match', $total) }}</span>@endif
                        <noscript><button>Apply</button></noscript>
                    </form>
                @endif
                @if ($files)
                    <div class="review-columns">
                        <div class="review-files"><ul class="files">
                            @foreach ($files as $id => $entry)
                                <li><a href="{{ route('cloud.pull', $browse + ['page' => $page, 'file' => $id]) }}#diff"
                                       @if ($entry['filename'] === ($selected['filename'] ?? null)) aria-current="true" @endif>
                                    <span class="file-label"><span class="filename">{{ basename($entry['filename']) }}</span>
                                        @if (dirname($entry['filename']) !== '.')<span class="file-dir">{{ dirname($entry['filename']) }}</span>@endif
                                        @if ($entry['previous_filename'])<span class="file-origin">From {{ $entry['previous_filename'] }}</span>@endif
                                    </span>
                                    <span class="file-states">@if (($marks[$id] ?? null) === $entry['version'])<span class="viewed-indicator">✓ Viewed</span>@endif{{ ucfirst($entry['status']) }}</span>
                                </a></li>
                            @endforeach
                        </ul></div>
                        <section id="diff" class="review-diff" aria-label="File diff">
                            @if ($selected)
                                <div class="toolbar"><div><h2 class="filename">{{ $selected['filename'] }}</h2>
                                    <span class="patch-count"><b>+{{ $selected['additions'] }}</b> −{{ $selected['deletions'] }}</span>
                                </div><label class="wrap-control"><input type="checkbox" id="wrap-lines">Wrap lines</label></div>
                                <div class="pull-controls">
                                    <nav aria-label="Navigate changed files">
                                        @if ($position > 0 || $page > 1)
                                            <a class="button-link" data-step="k" aria-label="Previous file" title="Previous file (K)" href="{{ route('cloud.pull', $browse + ($position > 0 ? ['page' => $page, 'file' => $ids[$position - 1]] : ['page' => $page - 1, 'edge' => 'last'])) }}#diff">←</a>
                                        @else
                                            <button disabled aria-label="Previous file">←</button>
                                        @endif
                                        <span>File {{ ($page - 1) * $size + $position + 1 }} of {{ $total }}</span>
                                        @if ($position < count($ids) - 1 || $page < $pages)
                                            <a class="button-link" data-step="j" aria-label="Next file" title="Next file (J)" href="{{ route('cloud.pull', $browse + ($position < count($ids) - 1 ? ['page' => $page, 'file' => $ids[$position + 1]] : ['page' => $page + 1])) }}#diff">→</a>
                                        @else
                                            <button disabled aria-label="Next file">→</button>
                                        @endif
                                    </nav>
                                    @include('hunk-navigation')
                                    @php($viewed = ($marks[$selected['id']] ?? null) === $selected['version'])
                                    <form method="post" action="{{ route('cloud.pull.viewed', $base) }}">
                                        @csrf
                                        @foreach (['scope' => hash('sha256', $scope), 'id' => $selected['id'], 'version' => $selected['version'], 'viewed' => (int) ! $viewed, 'page' => $page, 'slot' => $position, 'filter' => $filter, 'hide' => (int) $hide] as $name => $value)
                                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                                        @endforeach
                                        <button aria-pressed="{{ $viewed ? 'true' : 'false' }}">{{ $viewed ? '✓ Viewed' : 'Mark as viewed' }}</button>
                                    </form>
                                </div>
                                @if ($selected['patch'] !== '')
                                    <div data-line-review>
                                        <p class="line-status" data-line-status role="status"></p>
                                        @include('patch', ['patch' => $selected['patch'], 'section' => 'Pull request', 'inlineReview' => true])
                                        <template data-line-template>
                                            <form class="write-form inline-compose" data-review-write method="post" action="{{ route('cloud.pull.lines.post', $query) }}">
                                                @csrf
                                                @include('pull-revision')
                                                <input type="hidden" name="submission" value="{{ Str::uuid() }}">
                                                <input type="hidden" name="path" value="{{ $selected['filename'] }}">
                                                <input type="hidden" name="line"><input type="hidden" name="side">
                                                <label><strong data-line-label></strong><textarea name="body" required maxlength="65536" rows="3"></textarea></label>
                                                <div class="actions"><button>Post line comment</button><button type="button" data-cancel-line>Cancel</button></div>
                                            </form>
                                        </template>
                                        @if (old('body') && old('scope') === hash('sha256', $scope) && old('path') === $selected['filename'])
                                            <details open class="line-recovery"><summary>Unsent comment · {{ old('side') }} {{ old('line') }}</summary><p>{{ old('body') }}</p></details>
                                        @endif
                                    </div>
                                    @if ($selected['limited'])<p class="clean-workspace">Only the beginning of this diff is shown.</p>@endif
                                @else
                                    <p class="clean-workspace">GitHub did not provide a text diff for this file.</p>
                                @endif
                            @else
                                <p class="clean-workspace">This file is no longer on this page. Select another file.</p>
                            @endif
                        </section>
                    </div>
                @else
                    <p class="clean-workspace">{{ $count === 0 ? 'No changed files.' : ($hide || $filter !== '' ? 'No files match your filters.' : 'There are no files on this page.') }}</p>
                    @if ($page > 1)<p class="clean-workspace"><a href="{{ route('cloud.pull', $query) }}">First page</a></p>@endif
                @endif
            </section>
        @endisset
    </main>
    @include('loading')
</body>
</html>
