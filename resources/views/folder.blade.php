<li>
    @if ($folder->children)
        <details open data-folder="{{ $relative }}">
            <summary>@include('folder-label')</summary>
            <ul>@foreach ($folder->children as $child) @include('folder', ['folder' => $child, 'relative' => $relative === '.' ? $child->name : $relative.'/'.$child->name]) @endforeach</ul>
        </details>
    @else
        <div class="leaf">@include('folder-label')</div>
    @endif
</li>
