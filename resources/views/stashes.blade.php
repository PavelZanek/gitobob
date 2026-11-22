<!doctype html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ __('Stashes ·') }} {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
@php($query = ['repo' => $relative, 'changed' => request()->boolean('changed') ?: null])
<header><strong>GitoBob</strong><a href="{{ route('repository', $query) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">{{ __('Settings') }}</a></header>
<main class="repository-review">
    <section class="heading"><div><h1>{{ __('Stashes') }}</h1><p class="repository-meta">{{ $name }} · {{ $branch }}</p></div></section>
    <section class="panel">
        @forelse ($entries as $id => $label)
            <div class="branch-row"><a href="{{ route('repository.stashes', $query + ['stash' => $id]) }}" @if ($id === $oid) aria-current="true" @endif>{{ $label }}</a><code>{{ substr($id, 0, 8) }}</code></div>
        @empty
            <p class="clean-workspace">{{ __('No saved stashes in this repository.') }}</p>
        @endforelse
    </section>
    @if ($detail)
        <section class="panel">
            <div class="toolbar">
                <div><h2>{{ $entries[$oid] }}</h2><p class="repository-meta">{{ trans_choice('counts.changed_files', count($detail['files'])) }} {{ __('· Saved copy retained after apply') }}</p></div>
                <form method="post" action="{{ route('repository.stash.apply', $query) }}">
                    @csrf
                    <input type="hidden" name="version" value="{{ $version }}">
                    <input type="hidden" name="stash" value="{{ $oid }}">
                    <button>{{ __('Apply to') }} {{ $branch }}</button>
                </form>
            </div>
            <div class="review-columns">
                <nav class="review-files" aria-label="{{ __('Stashed files') }}"><ul class="files">
                    @foreach ($detail['files'] as $id => $file)
                        <li><a href="{{ route('repository.stashes', $query + ['stash' => $oid, 'file' => $id]) }}#diff" @if ($file === $detail['selected']) aria-current="true" @endif><span class="filename">{{ $file->path }}</span></a></li>
                    @endforeach
                </ul></nav>
                <section class="review-diff" id="diff">
                    @if ($detail['selected'])
                        <div class="toolbar"><h2 class="filename">{{ $detail['selected']->path }}</h2><label class="wrap-control"><input type="checkbox" id="wrap-lines">{{ __('Wrap lines') }}</label></div>
                        @foreach ($detail['patches'] as $section => $patch)
                            <h3 class="section-title">{{ __($section) }}</h3>
                            @include('patch')
                        @endforeach
                    @else
                        <p class="clean-workspace">{{ __('No file selected.') }}</p>
                    @endif
                </section>
            </div>
        </section>
    @endif
</main>
@include('loading')
</body>
</html>
