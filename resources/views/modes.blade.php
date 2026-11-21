<nav class="layout-picker app-modes" aria-label="Source">
    <a href="{{ isset($local) ? route('repository', ['repo' => $local]) : route('workspace', ['snapshot' => 1]) }}"
       @if (! request()->routeIs('cloud', 'cloud.pull')) aria-current="page" @endif>Local</a>
    <a href="{{ route('cloud', ['local' => $local ?? null]) }}"
       @if (request()->routeIs('cloud', 'cloud.pull')) aria-current="page" @endif>Cloud</a>
</nav>
