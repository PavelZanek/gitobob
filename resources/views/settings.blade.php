<!doctype html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>{{ __('Settings · GitoBob') }}</title>
</head>
<body>
<header><strong>GitoBob</strong><a href="{{ route('workspace', ['snapshot' => 1]) }}">{{ __('Workspace') }}</a></header>
<main>
    <h1>{{ __('Settings') }}</h1>
    @if (session('notice'))<p class="action-notice" role="status">{{ __(session('notice')) }}</p>@endif
    <section class="panel settings-panel" aria-label="{{ __('Application preferences') }}">
        <form class="settings-form" method="post" action="{{ route('settings.update') }}">
            @csrf
            <fieldset>
                <legend>{{ __('Language') }}</legend>
                <div class="theme-choices">
                    @foreach (['en' => 'English', 'cs' => 'Čeština'] as $value => $language)
                        <label lang="{{ $value }}"><input type="radio" name="language" value="{{ $value }}" @checked(old('language', $preferences->language) === $value)> {{ $language }}</label>
                    @endforeach
                </div>
                @error('language')<p role="alert">{{ $message }}</p>@enderror
            </fieldset>
            <fieldset>
                <legend>{{ __('Appearance') }}</legend>
                <div class="theme-choices">
                    @foreach (['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label)
                        <label><input type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $preferences->theme) === $value)> {{ __($label) }}</label>
                    @endforeach
                </div>
                @error('theme')<p role="alert">{{ $message }}</p>@enderror
            </fieldset>
            <div class="settings-depth">
                <label for="depth">{{ __('Repository search depth') }}</label>
                <input id="depth" name="depth" type="number" min="0" step="1" value="{{ old('depth', $preferences->depth) }}" placeholder="{{ __('Unlimited') }}" aria-describedby="depth-help">
                <p id="depth-help">{{ __('The selected folder is level 0. Leave blank to search all levels.') }}</p>
                @error('depth')<p role="alert">{{ $message }}</p>@enderror
            </div>
            <button>{{ __('Save settings') }}</button>
        </form>
    </section>
</main>
@include('loading')
</body>
</html>
