<p class="cloud-time">{{ __('Last updated') }} <time datetime="{{ $snapshot['updated'] }}">{{ $snapshot['updated'] }}</time></p>
@if ($repository === null)
    <section class="panel">
        <div class="toolbar"><h2>{{ __('Repositories (') }}{{ count($snapshot['repositories']) }})</h2></div>
        @forelse ($snapshot['repositories'] as $slug)
            <a data-repository-row class="cloud-row" href="{{ route('cloud', ['repository' => $slug]) }}">{{ $slug }}</a>
        @empty
            <p class="clean-workspace">{{ __('This GitHub account has no accessible repositories.') }}</p>
        @endforelse
        @if ($snapshot['repositories'])
            <p class="clean-workspace" data-no-repositories hidden>{{ __('No matching repositories.') }}</p>
        @endif
    </section>
@else
    <div class="cloud-columns">
        <section class="panel">
            <div class="toolbar"><h2>{{ __('Open pull requests (') }}{{ count($snapshot['pulls']) }})</h2></div>
            @forelse ($snapshot['pulls'] as $pr)
                <div class="cloud-row pull-row">
                    <a class="pull-link" data-prefetch="review" href="{{ route('cloud.pull', ['number' => $pr['number'], 'repository' => $repository]) }}">
                        <strong>{{ $pr['title'] }}</strong>
                        <p>#{{ $pr['number'] }} · {{ $pr['author'] }} @if ($pr['draft']) {{ __('· Draft') }} @endif</p>
                        <p class="cloud-refs">{{ $pr['head'] }} → {{ $pr['base'] }}</p>
                        <time datetime="{{ $pr['updated'] }}">{{ $pr['updated'] }}</time>
                    </a>
                    @isset($progress[$pr['number']])
                        @php($saved = $progress[$pr['number']])
                        <div class="review-progress">
                            <span>{{ $saved['viewed'] }}@if ($saved['total'] !== null) {{ __('of') }} {{ $saved['total'] }}@endif {{ __('files viewed') }}</span>
                            @if ($saved['total'] === null || $saved['viewed'] < $saved['total'])
                                <a class="button-link" href="{{ route('cloud.pull', ['number' => $pr['number'], 'repository' => $repository, 'resume' => 1]) }}">{{ __('Continue review') }}</a>
                            @endif
                        </div>
                    @endisset
                </div>
            @empty
                <p class="clean-workspace">{{ __('No open pull requests.') }}</p>
            @endforelse
        </section>
        <section class="panel">
            <div class="toolbar"><h2>{{ __('Branches (') }}{{ count($snapshot['branches']) }})</h2></div>
            @forelse ($snapshot['branches'] as $branch)
                <div class="cloud-row cloud-refs">{{ $branch }}</div>
            @empty
                <p class="clean-workspace">{{ __('No branches yet.') }}</p>
            @endforelse
        </section>
    </div>
@endif
