<div class="comparison-destination">
    @if ($action['message'] !== '')<span>{{ $action['message'] }}</span>@endif
    @if (isset($action['sync']))
        <a class="button-link" href="{{ route('repository.push.preview', ['repo' => $query['repo'], 'remote' => $remote, 'file_back' => $query['file'] ?? null] + $query) }}">{{ $action['label'] }}</a>
    @elseif (isset($action['url']))
        <form method="post" action="{{ route('repository.compare.action', $query) }}">
            @csrf
            <button name="destination" value="{{ hash('sha256', $action['url']) }}">{{ $action['label'] }}</button>
        </form>
    @endif
</div>
