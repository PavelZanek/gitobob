@php
    $staged = count($status->groups(true)['Staged'] ?? []);
    $conflicts = count($status->conflicts());
    $ahead = $status->ahead;
    $behind = $status->behind;
    $sync = route('repository.push.preview', $query + ['workflow' => 1]);
    $action = null;
    $label = null;
    $inline = true;
    if ($conflicts) {
        $label = 'Review conflicts';
        $action = route('repository.conflicts', $query);
        $inline = false;
    } elseif ($staged) {
        $label = 'Commit staged changes';
        $action = route('repository.commit.preview', $query);
    } elseif (($ahead && $behind) || (($ahead || $behind) && ! str_starts_with($status->upstreamRef, 'refs/remotes/'))) {
        $label = 'Compare branches';
        $action = route('repository.compare', $query + ['head' => 'refs/heads/'.$status->branch, 'base' => $status->upstreamRef]);
        $inline = false;
    } elseif ($behind) {
        $label = 'Pull changes';
        $action = $sync;
    } elseif ($ahead) {
        $protected = in_array($status->remoteTarget, ['refs/heads/main', 'refs/heads/staging'], true);
        $label = $protected ? 'Review outgoing commits' : trans_choice('counts.push', $ahead);
        $action = $sync;
    } elseif ($status->changedFiles && $selected) {
        $label = 'Review changes';
        $action = route('repository', $query + ['file' => $fileId]).'#diff';
        $inline = false;
    }
@endphp
<section class="branch-workflow" aria-label="{{ __('Branch workflow') }}">
    <div class="workflow-row">
        <span>
            @if ($conflicts){{ trans_choice('counts.conflicts', $conflicts) }} · @elseif ($staged){{ trans_choice('counts.staged', $staged) }} · @endif
            @if ($status->upstream === ''){{ $status->branch === 'Detached HEAD' ? __('Detached HEAD') : __('No upstream') }}
            @else{{ $status->upstream }} · {{ $ahead === null ? __('Tracking status unavailable') : ($ahead || $behind ? __(':ahead ahead · :behind behind', ['ahead' => $ahead, 'behind' => $behind]) : __('Up to date at last fetch')) }}@endif
        </span>
        <div class="actions">
            @if ($action)<a class="button-link" @if ($inline) data-workflow-preview @endif href="{{ $action }}">{{ __($label) }}</a>@endif
            @if ($ahead && ! $behind && ! $conflicts && str_starts_with($status->upstreamRef, 'refs/remotes/'))
                <form method="post" data-amend-preview action="{{ route('repository.amend.preview', $query) }}">@csrf<button>{{ __('Amend last commit') }}</button></form>
            @endif
        </div>
    </div>
    <div data-workflow-content hidden></div>
</section>
