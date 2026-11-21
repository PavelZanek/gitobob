@if ($folder->status)
    <a href="{{ route('repository', ['repo' => $relative, 'changed' => $onlyChanged ?: null]) }}"><span>{{ $folder->name }}</span></a>
    <small title="Current branch">{{ $folder->status->branch }}</small>
    <span class="file-count">{{ $folder->status->changedFiles }} {{ $folder->status->changedFiles === 1 ? 'changed file' : 'changed files' }}</span>
@else
    <span>{{ $folder->name }}</span>
@endif
