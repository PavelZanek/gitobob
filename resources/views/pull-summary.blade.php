@php
    $rollup = $summary['commits']['nodes'][0]['commit']['statusCheckRollup'] ?? [];
    $checks = $rollup['contexts']['nodes'] ?? [];
    $reviews = $summary['latestReviews']['nodes'] ?? [];
    $labels = ['SUCCESS' => 'Checks passed', 'FAILURE' => 'Checks failed', 'ERROR' => 'Check error', 'PENDING' => 'Checks pending', 'EXPECTED' => 'Checks expected'];
    $decisions = ['APPROVED' => 'Approved', 'CHANGES_REQUESTED' => 'Changes requested', 'REVIEW_REQUIRED' => 'Review required'];
@endphp
<span data-result-label>{{ __($labels[$rollup['state'] ?? ''] ?? 'No checks') }} · {{ __($decisions[$summary['reviewDecision'] ?? ''] ?? (count($reviews) ? 'See reviews' : 'No reviews yet')) }}</span>
<div data-result-details class="review-breakdown">
    <section><h3>{{ __('Checks') }}</h3>
        @forelse ($checks as $check)
            <p><strong>{{ $check['name'] ?? $check['context'] }}</strong> · {{ __(Str::headline(strtolower($check['conclusion'] ?? $check['status'] ?? $check['state']))) }}</p>
        @empty <p>{{ __('No checks reported for this commit.') }}</p> @endforelse
        @if (($rollup['contexts']['totalCount'] ?? 0) > count($checks))<p>{{ __('Showing the first') }} {{ count($checks) }} {{ __('checks.') }}</p>@endif
    </section>
    <section><h3>{{ __('Latest reviews') }}</h3>
        @forelse ($reviews as $review)
            <p><strong>{{ $review['author']['login'] ?? __('Deleted account') }}</strong> · {{ __(Str::headline(strtolower($review['state']))) }}</p>
        @empty <p>{{ __('No reviews submitted.') }}</p> @endforelse
    </section>
</div>
