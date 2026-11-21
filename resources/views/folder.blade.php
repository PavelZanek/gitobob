<li>
    @if ($folder->children)
        <details open>
            <summary><span>{{ $folder->name }}</span>@if ($folder->repository)<small>Git</small>@endif</summary>
            <ul>@foreach ($folder->children as $child) @include('folder', ['folder' => $child]) @endforeach</ul>
        </details>
    @else
        <div class="leaf"><span>{{ $folder->name }}</span><small>Git</small></div>
    @endif
</li>
