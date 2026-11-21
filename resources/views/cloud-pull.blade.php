<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ $repository }} #{{ $number }} · GitoBob</title>
</head>
<body class="review-page">
    <header><strong>GitoBob</strong><a href="{{ route('cloud', ['repository' => $repository]) }}">{{ $repository }}</a>@include('modes')</header>
    @php($query = ['number' => $number, 'repository' => $repository])
    <main class="repository-review">
        <section class="heading">
            <div>
                <h1>{{ $detail['pull']['title'] ?? 'Pull request' }}</h1>
                <p class="repository-meta">#{{ $number }} @if ($detail) · {{ $detail['pull']['head'] }} → {{ $detail['pull']['base'] }} @endif</p>
            </div>
            <div class="actions">
                <form method="post" action="{{ route('cloud.pull.open', $query) }}">@csrf<button>Open on GitHub</button></form>
                <a class="button-link" href="{{ route('cloud.pull', $query + ['page' => $page, 'file' => $file]) }}">Refresh</a>
            </div>
        </section>
        @if ($error)
            <p class="empty" role="alert">{{ $error }}</p>
        @else
            <section class="panel" aria-label="Changed files">
                <div class="toolbar">
                    <h2>Changed files ({{ $count }})</h2>
                    @if ($pages > 1)
                        <nav class="actions" aria-label="File pages">
                            @if ($page > 1)<a href="{{ route('cloud.pull', $query + ['page' => $page - 1]) }}">Previous</a>@endif
                            <span>Page {{ $page }} of {{ $pages }}</span>
                            @if ($page < $pages)<a href="{{ route('cloud.pull', $query + ['page' => $page + 1]) }}">Next</a>@endif
                        </nav>
                    @endif
                </div>
                @if ($count > \App\Git\GitHubClient::MAX_PR_FILES)
                    <p class="clean-workspace">GitHub provides the first 3,000 changed files.</p>
                @endif
                @if ($files)
                    <div class="review-columns">
                        <div class="review-files"><ul class="files">
                            @foreach ($files as $id => $entry)
                                <li><a href="{{ route('cloud.pull', $query + ['page' => $page, 'file' => $id, 'snapshot' => 1]) }}#diff"
                                       @if ($entry['filename'] === ($selected['filename'] ?? null)) aria-current="true" @endif>
                                    <span class="file-label"><span class="filename">{{ basename($entry['filename']) }}</span>
                                        @if (dirname($entry['filename']) !== '.')<span class="file-dir">{{ dirname($entry['filename']) }}</span>@endif
                                        @if ($entry['previous_filename'])<span class="file-origin">From {{ $entry['previous_filename'] }}</span>@endif
                                    </span>
                                    <span class="file-states">{{ ucfirst($entry['status']) }}</span>
                                </a></li>
                            @endforeach
                        </ul></div>
                        <section id="diff" class="review-diff" aria-label="File diff">
                            @if ($selected)
                                <div class="toolbar"><div><h2 class="filename">{{ $selected['filename'] }}</h2>
                                    <span class="patch-count"><b>+{{ $selected['additions'] }}</b> −{{ $selected['deletions'] }}</span>
                                </div><label class="wrap-control"><input type="checkbox" id="wrap-lines">Wrap lines</label></div>
                                @if ($selected['patch'] !== '')
                                    @include('patch', ['patch' => $selected['patch'], 'section' => 'Pull request'])
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
                    <p class="clean-workspace">{{ $count === 0 ? 'No changed files.' : 'There are no files on this page.' }}</p>
                    @if ($page > 1)<p class="clean-workspace"><a href="{{ route('cloud.pull', $query) }}">First page</a></p>@endif
                @endif
            </section>
        @endif
    </main>
    @include('loading')
</body>
</html>
