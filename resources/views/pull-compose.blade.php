<!doctype html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}"><title>{{ __('New pull request · GitoBob') }}</title>
</head>
<body>
<header><strong>GitoBob</strong><span>{{ $action['repository'] }}</span></header>
<main class="branch-page">
    <section class="heading"><div><h1>{{ __('New pull request') }}</h1><p class="repository-meta">{{ $action['source'] }} · {{ $head['target'] }} → {{ $action['repository'] }} · {{ $base['target'] }}</p></div></section>
    <section class="panel">
        <form data-pull-compose class="write-form branch-create" method="post" action="{{ route('repository.compare.compose', $query + ['identity' => $identity]) }}">
            @csrf
            <label for="pr-title">{{ __('Title') }}</label>
            <input id="pr-title" name="title" value="{{ old('title', $draft['title']) }}" maxlength="256" required>
            @error('title')<span role="alert">{{ $message }}</span>@enderror
            <label for="pr-body">{{ __('Description') }}</label>
            <textarea id="pr-body" name="body" rows="7" maxlength="65536">{{ old('body', $draft['body']) }}</textarea>
            @error('body')<span role="alert">{{ $message }}</span>@enderror
            <label for="pr-mode">{{ __('Review status') }}</label>
            <select id="pr-mode" name="mode"><option value="draft" @selected(old('mode', $draft['mode']) === 'draft')>{{ __('Draft') }}</option><option value="ready" @selected(old('mode', $draft['mode']) === 'ready')>{{ __('Ready for review') }}</option></select>
            <div class="actions"><button name="intent" value="create"><span class="compose-idle">{{ __('Create pull request') }}</span><span class="compose-pending">{{ __('Creating pull request…') }}</span></button><button name="intent" value="review" formnovalidate>{{ __('Save and review diff') }}</button></div>
        </form>
        <span class="navigation-status" role="status" data-compose-status></span>
    </section>
</main>
@include('loading')
</body></html>
