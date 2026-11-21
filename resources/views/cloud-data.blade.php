<p class="cloud-time">Last updated <time datetime="{{ $snapshot['updated'] }}">{{ $snapshot['updated'] }}</time></p>
@if ($repository === null)
    <section class="panel">
        <div class="toolbar"><h2>Repositories ({{ count($snapshot['repositories']) }})</h2></div>
        @forelse ($snapshot['repositories'] as $slug)
            <a data-repository-row class="cloud-row" href="{{ route('cloud', ['repository' => $slug]) }}">{{ $slug }}</a>
        @empty
            <p class="clean-workspace">This GitHub account has no accessible repositories.</p>
        @endforelse
        @if ($snapshot['repositories'])
            <p class="clean-workspace" data-no-repositories hidden>No matching repositories.</p>
        @endif
    </section>
@else
    <div class="cloud-columns">
        <section class="panel">
            <div class="toolbar"><h2>Open pull requests ({{ count($snapshot['pulls']) }})</h2></div>
            @forelse ($snapshot['pulls'] as $pr)
                <article class="cloud-row">
                    <strong>{{ $pr['title'] }}</strong>
                    <p>#{{ $pr['number'] }} · {{ $pr['author'] }} @if ($pr['draft']) · Draft @endif</p>
                    <p class="cloud-refs">{{ $pr['head'] }} → {{ $pr['base'] }}</p>
                    <time datetime="{{ $pr['updated'] }}">{{ $pr['updated'] }}</time>
                </article>
            @empty
                <p class="clean-workspace">No open pull requests.</p>
            @endforelse
        </section>
        <section class="panel">
            <div class="toolbar"><h2>Branches ({{ count($snapshot['branches']) }})</h2></div>
            @forelse ($snapshot['branches'] as $branch)
                <div class="cloud-row cloud-refs">{{ $branch }}</div>
            @empty
                <p class="clean-workspace">No branches yet.</p>
            @endforelse
        </section>
    </div>
@endif
