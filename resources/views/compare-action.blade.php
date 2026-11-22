<div class="comparison-destination">
    @if ($action['message'] !== '')<span>{{ __($action['message']) }}</span>@endif
    @if (isset($action['sync']))
        <a class="button-link" href="{{ route('repository.push.preview', ['repo' => $query['repo'], 'remote' => $remote, 'file_back' => $query['file'] ?? null] + $query) }}">{{ __($action['label']) }}</a>
    @elseif (isset($action['source']))
        <a class="button-link" href="{{ route('repository.compare.compose', $query + ['identity' => hash('sha256', $action['url'])]) }}">{{ __('Create pull request…') }}</a>
    @elseif (isset($action['number']))
        <a class="button-link" href="{{ route('cloud.pull', ['repository' => $action['repository'], 'number' => $action['number'], 'locate' => 1, 'file' => $query['file'] ?? null]) }}">{{ __('Open pull request') }}</a>
    @endif
</div>
