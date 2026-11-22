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
        $label = $protected ? 'Review outgoing commits' : 'Push '.$ahead.' '.Str::plural('commit', $ahead);
        $action = $sync;
    } elseif ($status->changedFiles && $selected) {
        $label = 'Review changes';
        $action = route('repository', $query + ['file' => $fileId]).'#diff';
        $inline = false;
    }
@endphp
<section class="branch-workflow" aria-label="Branch workflow">
    <div class="workflow-row">
        <span>
            @if ($conflicts){{ $conflicts }} conflicted {{ Str::plural('file', $conflicts) }} · @elseif ($staged){{ $staged }} staged {{ Str::plural('file', $staged) }} · @endif
            @if ($status->upstream === ''){{ $status->branch === 'Detached HEAD' ? 'Detached HEAD' : 'No upstream' }}
            @else{{ $status->upstream }} · {{ $ahead === null ? 'Tracking status unavailable' : ($ahead || $behind ? $ahead.' ahead · '.$behind.' behind' : 'Up to date at last fetch') }}@endif
        </span>
        <div class="actions">
            @if ($action)<a class="button-link" @if ($inline) data-workflow-preview @endif href="{{ $action }}">{{ $label }}</a>@endif
            @if ($ahead && ! $behind && ! $conflicts && str_starts_with($status->upstreamRef, 'refs/remotes/'))
                <form method="post" data-amend-preview action="{{ route('repository.amend.preview', $query) }}">@csrf<button>Amend last commit</button></form>
            @endif
        </div>
    </div>
    <div data-workflow-content hidden></div>
</section>
