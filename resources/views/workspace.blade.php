<!doctype html>
<html lang="{{ app()->getLocale() }}" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <title>{{ __('GitoBob · Repositories') }}</title>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
</head>
<body>
    <header><strong>GitoBob</strong><span>{{ __('Local repositories') }}</span>@include('modes')<a class="settings-link" href="{{ route('settings') }}">{{ __('Settings') }}</a></header>
    <main>
        <section class="heading">
            <div><h1>{{ __('Workspace') }}</h1>
                @if ($path)<p class="path">{{ $path }}</p>@endif
            </div>
            <form method="post" action="{{ route('workspace.choose') }}">
                @csrf
                <button>{{ $path ? __('Change folder') : __('Choose folder') }}</button>
            </form>
        </section>
        <div data-workspace-results data-repository-count="{{ $count }}" @if ($token) data-scan-url="{{ route('workspace', ['batch' => $token, 'changed' => $onlyChanged ?: null]) }}" @endif>
            @if ($available)
                <section class="panel" aria-label="{{ __('Repository tree') }}">
                    <div class="toolbar">
                        <nav class="layout-picker" aria-label="{{ __('Workspace view') }}">
                            <a aria-current="page" href="{{ route('workspace', ['snapshot' => 1, 'changed' => $onlyChanged ?: null]) }}">{{ __('Repositories') }}</a>
                            <a data-all-changes @if ($token) aria-disabled="true" @else data-prefetch href="{{ route('changes', ['snapshot' => 1, 'changed' => $onlyChanged ?: null]) }}" @endif>{{ __('All changes') }}</a>
                        </nav>
                        <div class="actions">
                            <a class="button-link" href="{{ route('workspace.fetch') }}">{{ __('Fetch repositories…') }}</a>
                            <a class="filter" role="switch" aria-checked="{{ $onlyChanged ? 'true' : 'false' }}"
                               href="{{ route('workspace', ['snapshot' => 1, 'changed' => $onlyChanged ? null : 1]) }}">{{ __('Only changed') }}</a>
                            <a class="button-link" href="{{ route('workspace', $onlyChanged ? ['changed' => 1] : []) }}">{{ __('Refresh') }}</a>
                        </div>
                    </div>
                    <p class="scan-status" role="status">{{ $token ? __('Finding repositories…') : __('Scan complete') }} <span>{{ $count }} {{ __('found') }}</span></p>
                    <div class="workspace-list" data-workspace-list>
                        @if ($tree)
                            <ul class="tree">@include('folder', ['folder' => $tree, 'relative' => '.'])</ul>
                        @elseif ($token)
                            <div class="scan-placeholder" aria-hidden="true"><i></i><i></i><i></i></div>
                        @else
                            <p class="clean-workspace">{{ $hasRepositories ? __('All repositories are clean.') : __('No repositories found') }}</p>
                        @endif
                    </div>
                </section>
            @elseif (! $path)
                <section class="empty"><h2>{{ __('Choose a workspace folder') }}</h2><p>{{ __('GitoBob looks for Git repositories in this folder and its subfolders.') }}</p></section>
            @elseif (! $available)
                <section class="empty"><h2>{{ __('Folder unavailable') }}</h2><p>{{ __('Check the folder location and permissions, or choose another folder.') }}</p><a href="{{ route('workspace') }}">{{ __('Try again') }}</a></section>
            @endif
        </div>
    </main>
    @include('loading')
</body>
</html>
