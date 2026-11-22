@if ($folder->status)
    <a data-open-project="{{ $relative }}" href="{{ route('repository', ['repo' => $relative, 'changed' => $onlyChanged ?: null]) }}"><span>{{ $folder->name }}</span></a>
    <small title="{{ __('Current branch') }}">{{ $folder->status->branch }}</small>
    <span class="file-count">{{ trans_choice('counts.changed_files', $folder->status->changedFiles) }}</span>
@else
    <span>{{ $folder->name }}</span>
@endif
