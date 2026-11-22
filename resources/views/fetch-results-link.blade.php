@if (request()->query('from') === 'workspace-fetch')
    <a href="{{ route('workspace.fetch') }}">Back to fetch results</a>
@endif
