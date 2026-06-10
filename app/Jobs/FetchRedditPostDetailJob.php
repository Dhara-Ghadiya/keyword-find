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
    ) {}

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

        $apiJsonUrl = 'https://www.reddit.com' . $this->permalink . '.json';
        $apiRssUrl  = 'https://www.reddit.com/comments/' . ltrim($this->permalink, '/') . '.rss';

        Log::info('FetchRedditPostDetailJob: start', [
            'result_id'   => $this->resultId,
            'search_id'   => $this->searchId,
            'permalink'   => $this->permalink,
            'api_json_url'=> $apiJsonUrl,
            'api_rss_url' => $apiRssUrl,
            'attempt'     => $this->attempts(),
            'max_tries'   => $this->tries,
        ]);

        try {
            // Already stored — idempotency guard
            if (RedditPost::where('result_id', $this->resultId)->exists()) {
                $success    = true;
                $failReason = 'already_stored';
                return;
            }

            // ── Attempt to fetch from Reddit API ──────────────────────────────
            // Initialize $data to null so we can fall through to the fallback when
            // a RuntimeException is caught on the last attempt (instead of releasing).
            $data = null;
            try {
                $data = $service->fetchPostDetail($this->permalink);
            } catch (RuntimeException $e) {
                $delays = $this->backoff();
                $delay  = $delays[$this->attempts() - 1] ?? end($delays);

                // Log the real exception regardless of whether we release or fall through.
                Log::warning('FetchRedditPostDetailJob: transient API error', [
                    'result_id'    => $this->resultId,
                    'search_id'    => $this->searchId,
                    'permalink'    => $this->permalink,
                    'api_json_url' => $apiJsonUrl,
                    'http_status'  => $service->getLastHttpStatus(),
                    'response'     => $service->getLastResponseSnippet(),
                    'exception'    => get_class($e),
                    'message'      => $e->getMessage(),
                    'file'         => $e->getFile(),
                    'line'         => $e->getLine(),
                    'attempt'      => $this->attempts(),
                    'max_tries'    => $this->tries,
                    'retry_in_s'   => $delay,
                ]);

                // CRITICAL: do NOT release on the last attempt.
                // Releasing causes the job to re-enter the queue. Laravel picks it up as
                // attempt (tries+1), detects attempts > maxTries, and throws
                // MaxAttemptsExceededException BEFORE handle() runs — losing the real
                // exception context and adding a confusing entry to failed_jobs.
                // On the last attempt we fall through to the fallback instead so the
                // reddit_posts record is still guaranteed without a failed_jobs entry.
                if ($this->attempts() < $this->tries) {
                    $this->release($delay);
                    return;
                }

                // Last attempt — $data stays null, falls through to fallback below.
                $failReason = 'transient_retries_exhausted: ' . $e->getMessage();
            }

            // ── API returned data → store it ──────────────────────────────────
            if ($data !== null) {
                $validationError = $this->validateApiPayload($data);

                if ($validationError) {
                    $failReason = 'api_validation_failed: ' . $validationError;
                    Log::warning('FetchRedditPostDetailJob: invalid API payload — using fallback', [
                        'result_id'  => $this->resultId,
                        'search_id'  => $this->searchId,
                        'permalink'  => $this->permalink,
                        'reason'     => $failReason,
                        'data_keys'  => array_keys($data),
                    ]);
                    // Fall through to fallback below
                } else {
                    $stored = $this->store([
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
                        'permalink'             => $data['permalink'],
                    ]);

                    if ($stored) {
                        $success    = true;
                        $failReason = null;
                        return;
                    }

                    // store() failed (DB constraint etc.) — fall through to fallback.
                    $failReason = 'db_store_failed_using_fallback';
                }
            } else {
                // API returned null (post deleted / private / blocked) OR transient exhausted.
                if (empty($failReason) || $failReason === 'not_started') {
                    $failReason = $service->getLastFailureReason() ?: 'api_returned_null';
                }

                Log::warning('FetchRedditPostDetailJob: Reddit API returned no data', [
                    'result_id'        => $this->resultId,
                    'search_id'        => $this->searchId,
                    'permalink'        => $this->permalink,
                    'api_json_url'     => $apiJsonUrl,
                    'reason'           => $failReason,
                    'http_status'      => $service->getLastHttpStatus(),
                    'response_snippet' => $service->getLastResponseSnippet(),
                    'attempt'          => $this->attempts(),
                ]);
            }

            // ── Fallback: use data from results table ─────────────────────────
            // Guarantees every result_id gets a reddit_posts record even when the
            // API is unavailable or the post has been deleted/privatised.
            $stored = $this->storeFallbackFromResult($failReason ?? 'unknown');

            $success    = $stored;
            $failReason = $stored ? 'fallback_from_results_table' : 'fallback_failed_no_result_found';

        } catch (Throwable $unexpected) {
            $failReason = get_class($unexpected) . ': ' . $unexpected->getMessage();

            Log::error('FetchRedditPostDetailJob: unexpected exception', [
                'result_id'  => $this->resultId,
                'search_id'  => $this->searchId,
                'permalink'  => $this->permalink,
                'exception'  => get_class($unexpected),
                'message'    => $unexpected->getMessage(),
                'file'       => $unexpected->getFile(),
                'line'       => $unexpected->getLine(),
                'trace'      => array_slice(array_map(
                    fn($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'),
                    $unexpected->getTrace()
                ), 0, 5),
            ]);

            // Still try the fallback so the result gets a reddit_posts record.
            $this->storeFallbackFromResult($failReason);

        } finally {
            $elapsed = (int) round((microtime(true) - $startedAt) * 1000);

            Log::info('FetchRedditPostDetailJob: summary', [
                'result_id'   => $this->resultId,
                'search_id'   => $this->searchId,
                'permalink'   => $this->permalink,
                'success'     => $success,
                'reason'      => $success ? ($failReason ?? 'stored_from_api') : $failReason,
                'elapsed_ms'  => $elapsed,
                'attempt'     => $this->attempts(),
                'http_status' => $service->getLastHttpStatus(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Fallback: store using results table data when API gives nothing
    // -------------------------------------------------------------------------

    /**
     * Creates a reddit_posts record using data already in the results table.
     * Guarantees a 1:1 ratio between results and reddit_posts even when Reddit's
     * API is unavailable or the post has been deleted / made private.
     */
    private function storeFallbackFromResult(string $apiFailReason = ''): bool
    {
        if (RedditPost::where('result_id', $this->resultId)->exists()) {
            Log::info('FetchRedditPostDetailJob: fallback skipped — record already exists', [
                'result_id' => $this->resultId,
                'permalink' => $this->permalink,
            ]);
            return true;
        }

        $result = Result::find($this->resultId);

        if (! $result) {
            Log::error('FetchRedditPostDetailJob: fallback failed — result not found', [
                'result_id'       => $this->resultId,
                'search_id'       => $this->searchId,
                'permalink'       => $this->permalink,
                'api_fail_reason' => $apiFailReason,
            ]);
            return false;
        }

        $stored = $this->store([
            'search_id'             => $this->searchId,
            'result_id'             => $this->resultId,
            'reddit_post_id'        => $result->external_id,
            'permalink'             => $this->permalink,
            'title'                 => $result->title,
            'selftext'              => $result->selftext ?? null,
            'url'                   => $result->url,
            'author'                => null,
            'ups'                   => 0,
            'downs'                 => 0,
            'total_awards_received' => 0,
            'created_utc'           => (int) ($result->created_at?->timestamp ?? 0),
        ]);

        if ($stored) {
            Log::info('FetchRedditPostDetailJob: stored via fallback (results table)', [
                'result_id'       => $this->resultId,
                'search_id'       => $this->searchId,
                'permalink'       => $this->permalink,
                'api_fail_reason' => $apiFailReason,
            ]);
        }

        return $stored;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Upsert a reddit_posts row.
     * Returns true on success, false when the DB operation fails (caller decides next step).
     * We use updateOrCreate keyed on permalink so a retry for the same post is idempotent.
     */
    private function store(array $payload): bool
    {
        $permalink = $payload['permalink'];
        unset($payload['permalink']);

        try {
            RedditPost::updateOrCreate(['permalink' => $permalink], $payload);
            return true;
        } catch (Throwable $e) {
            Log::error('FetchRedditPostDetailJob: DB upsert failed', [
                'result_id'  => $this->resultId,
                'search_id'  => $this->searchId,
                'permalink'  => $permalink,
                'exception'  => get_class($e),
                'message'    => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'payload_keys' => array_keys($payload),
            ]);
            return false;
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
     * Called by Laravel only if the job throws (not when it calls release()).
     * With the last-attempt fix above, this should now only fire for truly unexpected
     * exceptions — NOT for MaxAttemptsExceededException from transient rate-limiting.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('FetchRedditPostDetailJob: permanent failure — storing fallback', [
            'result_id'  => $this->resultId,
            'search_id'  => $this->searchId,
            'permalink'  => $this->permalink,
            'exception'  => get_class($exception),
            'message'    => $exception->getMessage(),
            'file'       => $exception->getFile(),
            'line'       => $exception->getLine(),
        ]);

        // Guarantee the 1:1 ratio even after permanent failure.
        $this->storeFallbackFromResult('permanent_failure: ' . $exception->getMessage());
    }
}
