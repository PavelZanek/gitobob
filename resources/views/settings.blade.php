<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
    <title>Settings · GitoBob</title>
</head>
<body>
<header><strong>GitoBob</strong><a href="{{ route('workspace', ['snapshot' => 1]) }}">Workspace</a></header>
<main>
    <h1>Settings</h1>
    @if (session('notice'))<p class="action-notice" role="status">{{ session('notice') }}</p>@endif
    <section class="panel settings-panel" aria-label="Application preferences">
        <form class="settings-form" method="post" action="{{ route('settings.update') }}">
            @csrf
            <fieldset>
                <legend>Appearance</legend>
                <div class="theme-choices">
                    @foreach (['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label)
                        <label><input type="radio" name="theme" value="{{ $value }}" @checked(old('theme', $preferences->theme) === $value)> {{ $label }}</label>
                    @endforeach
                </div>
                @error('theme')<p role="alert">{{ $message }}</p>@enderror
            </fieldset>
            <div class="settings-depth">
                <label for="depth">Repository search depth</label>
                <input id="depth" name="depth" type="number" min="0" step="1" value="{{ old('depth', $preferences->depth) }}" placeholder="Unlimited" aria-describedby="depth-help">
                <p id="depth-help">The selected folder is level 0. Leave blank to search all levels.</p>
                @error('depth')<p role="alert">{{ $message }}</p>@enderror
            </div>
            <button>Save settings</button>
        </form>
    </section>
</main>
@include('loading')
</body>
</html>
