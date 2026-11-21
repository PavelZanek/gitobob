<!doctype html>
<html lang="en" data-theme="{{ $preferences->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script type="module" src="{{ asset('navigation.js') }}"></script>
    <title>GitoBob · Repositories</title>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
</head>
<body>
    <header><strong>GitoBob</strong><span>Local repositories</span>@include('modes')<a class="settings-link" href="{{ route('settings') }}">Settings</a></header>
    <main>
        <section class="heading">
            <div><h1>Workspace</h1>
                @if ($path)<p class="path">{{ $path }}</p>@endif
            </div>
            <form method="post" action="{{ route('workspace.choose') }}">
                @csrf
                <button>{{ $path ? 'Change folder' : 'Choose folder' }}</button>
            </form>
        </section>
        <div data-workspace-results @if ($token) data-scan-url="{{ route('workspace', ['batch' => $token, 'changed' => $onlyChanged ?: null]) }}" data-repository-count="{{ $count }}" @endif>
            @if ($token)
                <p class="scan-status" role="status">Finding repositories… <span>{{ $count }} found</span></p>
            @endif
            @if ($hasRepositories)
                <section class="panel" aria-label="Repository tree">
                    <div class="toolbar">
                        <nav class="layout-picker" aria-label="Workspace view">
                            <a aria-current="page" href="{{ route('workspace', ['snapshot' => 1, 'changed' => $onlyChanged ?: null]) }}">Repositories</a>
                            @unless ($token)<a data-prefetch href="{{ route('changes', ['snapshot' => 1, 'changed' => $onlyChanged ?: null]) }}">All changes</a>@endunless
                        </nav>
                        <div class="actions">
                            <a class="filter" role="switch" aria-checked="{{ $onlyChanged ? 'true' : 'false' }}"
                               href="{{ route('workspace', ['snapshot' => 1, 'changed' => $onlyChanged ? null : 1]) }}">Only changed</a>
                            <a class="button-link" href="{{ route('workspace', $onlyChanged ? ['changed' => 1] : []) }}">Refresh</a>
                        </div>
                    </div>
                    @if ($tree)
                        <ul class="tree">@include('folder', ['folder' => $tree, 'relative' => '.'])</ul>
                    @else
                        <p class="clean-workspace">{{ $token ? 'No changed repositories found yet.' : 'All repositories are clean.' }}</p>
                    @endif
                </section>
            @elseif ($token)
                <div class="loading-panel" aria-hidden="true"><div class="loading-files"><i></i><i></i><i></i></div></div>
            @elseif (! $path)
                <section class="empty"><h2>Choose a workspace folder</h2><p>GitoBob looks for Git repositories in this folder and its subfolders.</p></section>
            @elseif (! $available)
                <section class="empty"><h2>Folder unavailable</h2><p>Check the folder location and permissions, or choose another folder.</p><a href="{{ route('workspace') }}">Try again</a></section>
            @else
                <section class="empty"><h2>No repositories found</h2><p>Choose a folder that contains a Git repository.</p><a href="{{ route('workspace') }}">Refresh</a></section>
            @endif
        </div>
    </main>
    @include('loading')
</body>
</html>
