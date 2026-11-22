@if ($folder->status)
    <a data-open-project="{{ $relative }}" href="{{ route('repository', ['repo' => $relative, 'changed' => $onlyChanged ?: null]) }}"><span>{{ $folder->name }}</span></a>
    <small title="Current branch">{{ $folder->status->branch }}</small>
    <span class="file-count">{{ $folder->status->changedFiles }} {{ $folder->status->changedFiles === 1 ? 'changed file' : 'changed files' }}</span>
@else
    <span>{{ $folder->name }}</span>
@endif
