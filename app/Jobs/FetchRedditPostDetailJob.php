<?php

namespace App\Jobs;

use App\Models\RedditPost;
use App\Models\Result;
use App\Services\RedditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Fetches and stores detailed Reddit post data for a single search result.
 *
 * Guarantee: every result_id that enters this job will have a reddit_posts
 * record when the job is done — even if the Reddit API returns no data.
 *
 * Priority order for data:
 *  1. Reddit permalink.json  — full data (ups, downs, author, created_utc, raw_json)
 *  2. Reddit comments/id.rss — partial data (title, selftext, author, created_utc)
 *  3. results table fallback — base data (title, selftext, url, permalink) already stored
 *
 * The fallback (3) ensures results_count == reddit_posts count always.
 */
class FetchRedditPostDetailJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int    $resultId,
        public readonly int    $searchId,
        public readonly string $permalink,
    ) {
        $this->onQueue('reddit-detail');
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    // -------------------------------------------------------------------------
    // Main handler
    // -------------------------------------------------------------------------

    public function handle(RedditService $service): void
    {
        $startedAt  = microtime(true);
        $success    = false;
        $failReason = 'not_started';

        Log::info('FetchRedditPostDetailJob: start', [
            'result_id' => $this->resultId,
            'permalink' => $this->permalink,
            'attempt'   => $this->attempts(),
        ]);

        try {
            // Already stored — idempotency guard
            if (RedditPost::where('result_id', $this->resultId)->exists()) {
                $success    = true;
                $failReason = 'already_stored';
                return;
            }

            // ── Attempt to fetch from Reddit API ──────────────────────────────
            try {
                $data = $service->fetchPostDetail($this->permalink);
            } catch (RuntimeException $e) {
                // Transient: 429 or timeout → release silently, retry with backoff
                $delays = $this->backoff();
                $delay  = $delays[$this->attempts() - 1] ?? end($delays);

                Log::warning('FetchRedditPostDetailJob: transient error — retrying', [
                    'result_id'   => $this->resultId,
                    'permalink'   => $this->permalink,
                    'http_status' => $service->getLastHttpStatus(),
                    'error'       => $e->getMessage(),
                    'attempt'     => $this->attempts(),
                    'retry_in_s'  => $delay,
                ]);

                $this->release($delay);
                return;
            }

            // ── API returned data → store it ──────────────────────────────────
            if ($data) {
                $validationError = $this->validateApiPayload($data);

                if ($validationError) {
                    $failReason = 'api_validation_failed: ' . $validationError;
                    Log::warning('FetchRedditPostDetailJob: invalid API payload — using fallback', [
                        'result_id' => $this->resultId,
                        'permalink' => $this->permalink,
                        'reason'    => $failReason,
                    ]);
                    // Fall through to fallback below
                } else {
                    $this->store([
                        'search_id'             => $this->searchId,
                        'result_id'             => $this->resultId,
                        'reddit_post_id'        => $data['reddit_post_id'] ?? null,
                        'title'                 => $data['title'],
                        'selftext'              => $data['selftext'] ?? null,
                        'url'                   => $data['url'],
                        'author'                => $data['author'] ?? null,
                        'ups'                   => (int) ($data['ups'] ?? 0),
                        'downs'                 => (int) ($data['downs'] ?? 0),
                        'total_awards_received' => (int) ($data['total_awards_received'] ?? 0),
                        'created_utc'           => (int) ($data['created_utc'] ?? now()->timestamp),
                        'raw_json'              => $data['raw_json'] ?? null,
                        'permalink'             => $data['permalink'],
                    ]);

                    $success    = true;
                    $failReason = null;
                    return;
                }
            } else {
                $failReason = $service->getLastFailureReason() ?: 'api_returned_null';

                Log::warning('FetchRedditPostDetailJob: Reddit API returned no data', [
                    'result_id'        => $this->resultId,
                    'permalink'        => $this->permalink,
                    'reason'           => $failReason,
                    'http_status'      => $service->getLastHttpStatus(),
                    'response_snippet' => $service->getLastResponseSnippet(),
                    'attempt'          => $this->attempts(),
                ]);
            }

            // ── Fallback: use data from results table ─────────────────────────
            // Guarantees every result_id gets a reddit_posts record.
            $stored = $this->storeFallbackFromResult($failReason);

            $success    = $stored;
            $failReason = $stored ? 'fallback_from_results_table' : 'fallback_failed_no_result_found';

        } catch (Throwable $unexpected) {
            $failReason = class_basename($unexpected) . ': ' . $unexpected->getMessage();

            Log::error('FetchRedditPostDetailJob: unexpected exception', [
                'result_id'  => $this->resultId,
                'permalink'  => $this->permalink,
                'error_type' => class_basename($unexpected),
                'error'      => $unexpected->getMessage(),
            ]);

            // Still try the fallback so the result gets a reddit_posts record
            $this->storeFallbackFromResult($failReason);

        } finally {
            $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('FetchRedditPostDetailJob: summary', [
                'result_id'  => $this->resultId,
                'permalink'  => $this->permalink,
                'success'    => $success,
                'reason'     => $success ? ($failReason ?? 'stored_from_api') : $failReason,
                'elapsed_ms' => $elapsed,
                'attempt'    => $this->attempts(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Fallback: store using results table data when API gives nothing
    // -------------------------------------------------------------------------

    /**
     * Creates a reddit_posts record using data already stored in results table.
     * Ensures 25 results → 25 reddit_posts, even for deleted / private posts.
     */
    private function storeFallbackFromResult(string $apiFailReason = ''): bool
    {
        if (RedditPost::where('result_id', $this->resultId)->exists()) {
            return true; // already stored by a concurrent job or previous attempt
        }

        $result = Result::find($this->resultId);

        if (! $result) {
            Log::error('FetchRedditPostDetailJob: fallback failed — result not found', [
                'result_id' => $this->resultId,
            ]);
            return false;
        }

        // raw_data is populated when search was done via search.json —
        // it contains the full Reddit API post object including ups/downs/author.
        $rawData    = is_array($result->raw_data) ? $result->raw_data : [];
        $hasRawData = ! empty($rawData) && isset($rawData['id']);

        $this->store([
            'search_id'             => $this->searchId,
            'result_id'             => $this->resultId,
            'reddit_post_id'        => $result->external_id ?? ($rawData['id'] ?? null),
            'permalink'             => $this->permalink,
            'title'                 => $result->title,
            'selftext'              => $result->selftext ?? null,
            'url'                   => $result->url,
            'author'                => $rawData['author'] ?? null,
            'ups'                   => (int) ($rawData['ups'] ?? 0),
            'downs'                 => (int) ($rawData['downs'] ?? 0),
            'total_awards_received' => (int) ($rawData['total_awards_received'] ?? 0),
            'created_utc'           => (int) ($rawData['created_utc'] ?? $result->created_at?->timestamp ?? 0),
            'raw_json'              => $hasRawData ? $rawData : null,
        ]);

        Log::info('FetchRedditPostDetailJob: stored via fallback (results table)', [
            'result_id'       => $this->resultId,
            'permalink'       => $this->permalink,
            'api_fail_reason' => $apiFailReason,
            'has_raw_data'    => $hasRawData,
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function store(array $payload): void
    {
        $permalink = $payload['permalink'];
        unset($payload['permalink']);

        try {
            RedditPost::updateOrCreate(['permalink' => $permalink], $payload);
        } catch (Throwable $e) {
            Log::error('FetchRedditPostDetailJob: DB insert failed', [
                'result_id'  => $this->resultId,
                'permalink'  => $this->permalink,
                'error'      => $e->getMessage(),
                'payload'    => array_merge(['permalink' => $permalink], $payload),
            ]);
        }
    }

    private function validateApiPayload(array $data): ?string
    {
        if (empty($data['permalink'])) return 'missing_permalink';
        if (empty($data['title']))     return 'missing_title';
        if (empty($data['url']))       return 'missing_url';
        if (! isset($data['ups']))     return 'missing_ups';
        return null;
    }

    // -------------------------------------------------------------------------
    // Permanent failure
    // -------------------------------------------------------------------------

    /**
     * Called when all $tries retry attempts are exhausted.
     * The fallback ensures a reddit_posts record still gets created.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('FetchRedditPostDetailJob: all retries exhausted — storing fallback', [
            'result_id'  => $this->resultId,
            'permalink'  => $this->permalink,
            'error_type' => class_basename($exception),
            'error'      => $exception->getMessage(),
        ]);

        // Guarantee the 1:1 ratio even after permanent failure
        $this->storeFallbackFromResult('retries_exhausted: ' . $exception->getMessage());
    }
}
