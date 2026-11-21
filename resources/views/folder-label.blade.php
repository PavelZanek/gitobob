<span>{{ $folder->name }}</span>
@if ($folder->status)
    <small title="Current branch">{{ $folder->status->branch }}</small>
    <span class="file-count">{{ $folder->status->changedFiles }} {{ $folder->status->changedFiles === 1 ? 'changed file' : 'changed files' }}</span>
@endif
