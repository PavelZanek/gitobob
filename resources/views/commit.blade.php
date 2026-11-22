@php($amend = $amend ?? false)
<!doctype html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ $amend ? __('Amend last commit') : __('Commit staged changes') }} · {{ $name }} · GitoBob</title>
</head>
<body class="review-page">
<header><strong>GitoBob</strong><a href="{{ route('repository', ['repo' => $relative]) }}">{{ $name }}</a><a class="settings-link" href="{{ route('settings') }}">{{ __('Settings') }}</a></header>
<main class="repository-review">
    <section class="heading"><div><h1>{{ $amend ? __('Amend last commit') : __('Commit staged changes') }}</h1><p class="repository-meta">{{ $relative === '.' ? $name : $relative }} · {{ $branch }}</p></div></section>
    @if (session('notice'))<p class="action-notice" role="status">{{ __(session('notice')) }}</p>@endif
    <section class="panel">
        @if ($summary === '' && ! $amend)
            <p class="clean-workspace">{{ __('No staged changes to commit.') }}</p>
        @else
            @if ($amend)<p class="cloud-row">{{ __('Editing local commit') }} <code>{{ substr($head, 0, 8) }}</code> {{ __('· Upstream verified') }}</p>@endif
            @if ($summary !== '')<div class="toolbar"><h2>{{ __('Staged files') }}</h2></div>
            <pre class="commit-summary">{{ $summary }}</pre>@else<p class="cloud-row">{{ __('Only the commit message will change.') }}</p>@endif
            <form class="write-form" method="post" action="{{ route($amend ? 'repository.amend' : 'repository.commit', ['repo' => $relative]) }}">
                @csrf
                <input type="hidden" name="version" value="{{ $version }}">
                <label for="commit-message">{{ __('Commit message') }}</label>
                <textarea id="commit-message" name="message" rows="3" required maxlength="16000">{{ old('message', $original ?? '') }}</textarea>
                @error('message')<p role="alert">{{ $message }}</p>@enderror
                <button>{{ $amend ? __('Amend local commit') : __('Create local commit') }}</button>
            </form>
            @if ($summary !== '')<div class="toolbar"><h2>{{ __('Staged diff') }}</h2></div>
            @include('patch', ['section' => 'Staged'])@endif
        @endif
    </section>
</main>
@include('loading')
</body>
</html>
