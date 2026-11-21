<li>
    @if ($folder->children)
        <details open>
            <summary>@include('folder-label')</summary>
            <ul>@foreach ($folder->children as $child) @include('folder', ['folder' => $child]) @endforeach</ul>
        </details>
    @else
        <div class="leaf">@include('folder-label')</div>
    @endif
</li>
