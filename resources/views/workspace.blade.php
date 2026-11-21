<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GitoBob · Repositories</title>
    <link rel="stylesheet" href="{{ asset('workspace.css') }}">
</head>
<body>
    <header><strong>GitoBob</strong><span>Local repositories</span></header>
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
        @if ($tree)
            <section class="panel" aria-label="Repository tree">
                <div class="toolbar"><h2>Repositories</h2><a href="{{ route('workspace') }}">Refresh</a></div>
                <ul class="tree">@include('folder', ['folder' => $tree])</ul>
            </section>
        @elseif (! $path)
            <section class="empty"><h2>Choose a workspace folder</h2><p>GitoBob looks for Git repositories in this folder and its subfolders.</p></section>
        @elseif (! $available)
            <section class="empty"><h2>Folder unavailable</h2><p>Check the folder location and permissions, or choose another folder.</p><a href="{{ route('workspace') }}">Try again</a></section>
        @else
            <section class="empty"><h2>No repositories found</h2><p>Choose a folder that contains a Git repository.</p><a href="{{ route('workspace') }}">Refresh</a></section>
        @endif
    </main>
</body>
</html>
