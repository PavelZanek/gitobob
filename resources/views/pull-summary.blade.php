@php
    $rollup = $summary['commits']['nodes'][0]['commit']['statusCheckRollup'] ?? [];
    $checks = $rollup['contexts']['nodes'] ?? [];
    $reviews = $summary['latestReviews']['nodes'] ?? [];
    $labels = ['SUCCESS' => 'Checks passed', 'FAILURE' => 'Checks failed', 'ERROR' => 'Check error', 'PENDING' => 'Checks pending', 'EXPECTED' => 'Checks expected'];
    $decisions = ['APPROVED' => 'Approved', 'CHANGES_REQUESTED' => 'Changes requested', 'REVIEW_REQUIRED' => 'Review required'];
@endphp
<span data-result-label>{{ $labels[$rollup['state'] ?? ''] ?? 'No checks' }} · {{ $decisions[$summary['reviewDecision'] ?? ''] ?? (count($reviews) ? 'See reviews' : 'No reviews yet') }}</span>
<div data-result-details class="review-breakdown">
    <section><h3>Checks</h3>
        @forelse ($checks as $check)
            <p><strong>{{ $check['name'] ?? $check['context'] }}</strong> · {{ Str::headline(strtolower($check['conclusion'] ?? $check['status'] ?? $check['state'])) }}</p>
        @empty <p>No checks reported for this commit.</p> @endforelse
        @if (($rollup['contexts']['totalCount'] ?? 0) > count($checks))<p>Showing the first {{ count($checks) }} checks.</p>@endif
    </section>
    <section><h3>Latest reviews</h3>
        @forelse ($reviews as $review)
            <p><strong>{{ $review['author']['login'] ?? 'Deleted account' }}</strong> · {{ Str::headline(strtolower($review['state'])) }}</p>
        @empty <p>No reviews submitted.</p> @endforelse
    </section>
</div>
