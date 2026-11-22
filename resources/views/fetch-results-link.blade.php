@if (request()->query('from') === 'workspace-fetch')
    <a href="{{ route('workspace.fetch') }}">{{ __('Back to fetch results') }}</a>
@endif
