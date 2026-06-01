<?php

namespace App\Jobs;

use App\Models\RedditPost;
use App\Services\RedditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches and stores detailed Reddit post data for a single search result.
 *
 * Dispatched automatically by FetchKeywordResultsJob after storing
 * Reddit search results. Runs in the background via the database queue.
 */
class FetchRedditPostDetailJob implements ShouldQueue
{
    use Queueable;

    /** Maximum number of attempts before marking as failed. */
    public int $tries = 3;

    /** Per-attempt timeout in seconds. */
    public int $timeout = 30;

    /**
     * Progressive back-off delays (seconds) between retries.
     * Attempt 1 → 30 s, Attempt 2 → 60 s, Attempt 3 → 120 s.
     */
    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(
        public readonly int    $resultId,
        public readonly int    $searchId,
        public readonly string $permalink,
    ) {}

    public function handle(RedditService $service): void
    {
        // Idempotency guard: skip if a reddit_post already exists for this result
        if (RedditPost::where('result_id', $this->resultId)->exists()) {
            return;
        }

        $data = $service->fetchPostDetail($this->permalink);

        if (! $data) {
            Log::info('Reddit post detail: no data returned', [
                'result_id' => $this->resultId,
                'permalink' => $this->permalink,
            ]);
            return;
        }

        RedditPost::updateOrCreate(
            ['permalink' => $data['permalink']],
            [
                'search_id'             => $this->searchId,
                'result_id'             => $this->resultId,
                'reddit_post_id'        => $data['reddit_post_id'] ?? null,
                'title'                 => $data['title'],
                'selftext'              => $data['selftext'],
                'url'                   => $data['url'],
                'author'                => $data['author'] ?? null,
                'ups'                   => $data['ups'],
                'downs'                 => $data['downs'],
                'total_awards_received' => $data['total_awards_received'],
                'created_utc'           => $data['created_utc'],
                'raw_json'              => $data['raw_json'] ?? null,
            ]
        );

        Log::info('Reddit post detail stored', [
            'result_id' => $this->resultId,
            'permalink' => $this->permalink,
        ]);
    }

    /** Called after all retry attempts are exhausted. */
    public function failed(Throwable $exception): void
    {
        Log::error('FetchRedditPostDetailJob permanently failed', [
            'result_id' => $this->resultId,
            'permalink' => $this->permalink,
            'error'     => $exception->getMessage(),
        ]);
    }
}
