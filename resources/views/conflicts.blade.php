<!doctype html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ __('Resolve conflicts ·') }} {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
@php($query = ['repo' => $relative])
@php($selection = $query + ['file' => $id])
<header><strong>GitoBob</strong><a href="{{ route('repository', $query) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">{{ __('Settings') }}</a></header>
<main class="repository-review" data-refresh-url="{{ route('repository.conflicts', $selection) }}" data-revision="{{ $revision }}" data-file="{{ $id }}">
    <section class="heading">
        <div><h1>{{ __('Resolve conflicts') }}</h1><p class="repository-meta">{{ $name }} · {{ $branch }}</p></div>
        <a class="button-link" href="{{ route('repository.conflicts', $selection) }}">{{ __('Refresh') }}</a>
    </section>
    @if (session('notice'))<p class="action-notice branch-message" role="status">{{ __(session('notice')) }}</p>@endif
    <section class="panel">
        <div class="toolbar">
            <h2>{{ trans_choice('counts.conflicts', count($files)) }}</h2>
            @if (count($files) > 1)
                @php($ids = array_keys($files))
                <a class="button-link" href="{{ route('repository.conflicts', $query + ['file' => $ids[(array_search($id, $ids, true) + 1) % count($ids)]]) }}">{{ __('Next conflict →') }}</a>
            @endif
        </div>
        @if ($selected)
            <div class="review-columns">
                <nav class="review-files" aria-label="{{ __('Conflicted files') }}"><ul class="files">
                    @foreach ($files as $key => $file)
                        <li><a href="{{ route('repository.conflicts', $query + ['file' => $key]) }}" @if ($key === $id) aria-current="true" @endif><span class="filename">{{ $file->path }}</span></a></li>
                    @endforeach
                </ul></nav>
                <section class="review-diff" aria-label="{{ __('Conflict details') }}">
                    <div class="toolbar"><h2 class="filename">{{ $selected->path }}</h2></div>
                    <div class="conflict-sides">
                        @foreach (['current' => 'Current branch', 'incoming' => $incoming] as $side => $label)
                            <section><h3 class="section-title">{{ __($label) }}</h3><pre>{{ $sides[$side] }}</pre></section>
                        @endforeach
                    </div>
                    <div class="toolbar"><h2>{{ __('Working file') }}</h2>
                        @if ($working['editable'])
                            <form method="post" action="{{ route('repository.conflicts.editor', $selection) }}">@csrf<button>{{ __('Open in editor') }}</button></form>
                        @endif
                    </div>
                    <pre class="conflict-result">{{ $working['content'] }}</pre>
                    <div class="toolbar">
                        <p class="repository-meta">{{ $working['markers'] ? __('Remove the conflict markers and save the file in your editor.') : __('Review the working file before staging this resolution.') }}</p>
                        <form method="post" action="{{ route('repository.conflicts.resolve', $query) }}">
                            @csrf
                            <input type="hidden" name="file" value="{{ $id }}">
                            <input type="hidden" name="version" value="{{ $working['version'] }}">
                            <button @disabled($working['markers'] || $working['version'] === null)>{{ __('Mark as resolved') }}</button>
                        </form>
                    </div>
                </section>
            </div>
        @else
            <div class="toolbar"><p>{{ __('All conflicts resolved.') }}</p><a class="button-link" href="{{ route('repository.commit.preview', $query) }}">{{ __('Review staged changes') }}</a></div>
        @endif
    </section>
</main>
@include('loading')
</body>
</html>
