<?php

namespace App\Jobs;

use App\Models\Result;
use App\Models\Search;
use App\Services\RedditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches one batch (up to 25) of Reddit posts for a keyword, storing results
 * with batch_number and updating the search's pagination state (reddit_after,
 * total_fetched, is_fully_synced).
 *
 * Calling store() a second time with the same keyword picks up where the last
 * batch left off — fetching posts 26–50, 51–75, etc.
 *
 * Google Custom Search is fetched only on the first batch (no pagination support).
 */
class FetchKeywordResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 5;
    public int $timeout = 300;

    /**
     * Seconds to wait before each successive retry attempt.
     * Gives Reddit's CDN time to un-block between retries after a 403/429.
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function __construct(
        public readonly int $searchId
    ) {}

    public function handle(RedditService $reddit): void
    {
        // Lock the record to prevent two simultaneous jobs from using the same after token
        $search = DB::transaction(fn () => Search::lockForUpdate()->find($this->searchId));

        if (! $search) {
            Log::warning('FetchKeywordResultsJob: search not found', ['search_id' => $this->searchId]);
            return;
        }

        if ($search->is_fully_synced) {
            Log::info('FetchKeywordResultsJob: already fully synced', [
                'search_id' => $this->searchId,
                'keyword'   => $search->keyword,
            ]);
            return;
        }

        $batchNumber = $search->getNextBatch();
        $afterToken  = $search->reddit_after;
        $isFirstBatch = $batchNumber === 1;

        $search->update(['status' => 'running']);

        // Build the expected API URL for this batch — useful for manual verification.
        $expectedApiUrl = 'https://www.reddit.com/search.json?' . http_build_query(
            array_filter([
                'q'     => $search->keyword,
                'limit' => 25,
                'sort'  => 'new',
                't'     => 'all',
                'after' => $afterToken,
            ])
        );

        Log::info('FetchKeywordResultsJob: starting batch', [
            'search_id'       => $this->searchId,
            'keyword'         => $search->keyword,
            'batch'           => $batchNumber,
            'after_token'     => $afterToken,
            'expected_api_url'=> $expectedApiUrl,
            'total_fetched'   => $search->total_fetched,
        ]);

        try {
            // ── Reddit API (paginated, sort=new) ──────────────────────────────
            $apiResult = $reddit->searchPosts($search->keyword, $afterToken);

            $posts             = $apiResult['posts'];
            $newAfter          = $apiResult['after'];
            // These fields are only set by the JSON path; RSS path uses different counters.
            $totalChildrenReddit  = $apiResult['total_children']   ?? count($posts);
            $filteredNonPost      = $apiResult['filtered_nonpost']  ?? 0;
            $actualApiUrl         = $apiResult['api_url']           ?? $expectedApiUrl;

            if (empty($posts)) {
                // is_fully_synced = true ONLY when Reddit signals no more results (after = null).
                // If $newAfter is non-null here it means the page had no storable posts (all
                // entries were filtered as subreddits / users by the kind=t3 filter), yet Reddit
                // still has more pages. In that case we advance the cursor WITHOUT marking done.
                if ($newAfter !== null) {
                    Log::warning('FetchKeywordResultsJob: empty post page with non-null after token — advancing cursor', [
                        'search_id' => $this->searchId,
                        'batch'     => $batchNumber,
                        'after_in'  => $afterToken,
                        'after_out' => $newAfter,
                    ]);
                    DB::table('searches')->where('id', $this->searchId)->update([
                        'status'          => 'completed',
                        'reddit_after'    => $newAfter,
                        'is_fully_synced' => false,
                        'last_synced_at'  => now(),
                    ]);
                } else {
                    Log::info('FetchKeywordResultsJob: no posts and after=null — marking fully synced', [
                        'search_id'     => $this->searchId,
                        'batch'         => $batchNumber,
                        'total_fetched' => $search->total_fetched,
                    ]);
                    $search->update([
                        'status'          => $search->total_fetched > 0 ? 'completed' : 'no_results',
                        'is_fully_synced' => true,
                        'last_synced_at'  => now(),
                    ]);
                }
                return;
            }

            // ── Store Reddit results ──────────────────────────────────────────
            // $redditStored tracks only Reddit posts — used to advance total_fetched.
            // Google results are NOT counted because getCurrentBatch() divides total_fetched
            // by 25 to derive batch numbers; including Google posts inflates that value and
            // assigns wrong batch_number labels to subsequent Reddit fetches.
            $redditStored = 0;
            $savedResults = collect();
            $duplicates   = 0;
            $skipped      = 0;

            foreach ($posts as $post) {
                $redditId  = $post['id'] ?? null;
                $permalink = $post['permalink'] ?? null;

                // Safety guard: skip entries that are not real posts.
                // The service already filters these, but log them if any slip through.
                if (! $redditId || ! str_contains($permalink ?? '', '/comments/')) {
                    $skipped++;
                    Log::info('FetchKeywordResultsJob: skipping non-post entry', [
                        'search_id' => $this->searchId,
                        'batch'     => $batchNumber,
                        'id'        => $redditId,
                        'permalink' => $permalink,
                        'title'     => Str::limit($post['title'] ?? '', 80, ''),
                        'reason'    => ! $redditId ? 'missing_id' : 'no_comments_in_permalink',
                    ]);
                    continue;
                }

                $selftext = $this->cleanSelftext($post['selftext'] ?? '');

                // firstOrCreate prevents duplicate results across batches.
                $result = $search->results()->firstOrCreate(
                    ['external_id' => $redditId],
                    [
                        'batch_number' => $batchNumber,
                        'source'       => 'reddit',
                        'permalink'    => $permalink,
                        'title'        => Str::limit($post['title'] ?? 'Untitled', 255, ''),
                        'url'          => $post['url'] ?? '#',
                        'selftext'     => $selftext,
                    ]
                );

                if ($result->wasRecentlyCreated) {
                    $redditStored++;
                    $savedResults->push($result);
                } else {
                    $duplicates++;
                    Log::info('FetchKeywordResultsJob: duplicate post skipped', [
                        'search_id' => $this->searchId,
                        'batch'     => $batchNumber,
                        'reddit_id' => $redditId,
                        'permalink' => $permalink,
                    ]);
                }
            }

            // ── Google results (first batch only — no after-token pagination) ──
            $googleStored = 0;
            if ($isFirstBatch) {
                foreach ($this->searchGoogle($search->keyword, 25) as $googleResult) {
                    $result = $search->results()->firstOrCreate(
                        ['external_id' => $googleResult['external_id'], 'source' => 'google'],
                        [
                            'batch_number' => $batchNumber,
                            'source'       => 'google',
                            'permalink'    => null,
                            'title'        => $googleResult['title'],
                            'url'          => $googleResult['url'],
                            'selftext'     => null,
                        ]
                    );

                    if ($result->wasRecentlyCreated) {
                        $googleStored++;
                    }
                }
            }

            // ── Update search pagination state ────────────────────────────────
            // is_fully_synced = true ONLY when Reddit's API returns after = null.
            // Never infer completion from insert counts, duplicate counts, or total_fetched.
            $isFullySynced = $newAfter === null;

            // Atomic SQL increment for total_fetched avoids a read-modify-write race when
            // the user double-clicks Retry and two jobs overlap briefly. Only Reddit posts
            // advance the counter — Google posts must not skew the batch-number arithmetic.
            DB::table('searches')->where('id', $this->searchId)->update([
                'status'          => 'completed',
                'reddit_after'    => $newAfter,
                'is_fully_synced' => $isFullySynced,
                'last_synced_at'  => now(),
                'total_fetched'   => DB::raw('total_fetched + ' . $redditStored),
            ]);

            // ── Dispatch detail-fetch jobs for new Reddit results ─────────────
            $this->dispatchDetailJobs($savedResults, $search->id);

            $search->refresh();

            Log::info('FetchKeywordResultsJob: batch complete', [
                'search_id'              => $this->searchId,
                'keyword'                => $search->keyword,
                'batch'                  => $batchNumber,
                'api_url'                => $actualApiUrl,
                'after_in'               => $afterToken,
                'after_out'              => $newAfter,
                // reddit_children_total: raw count Reddit returned BEFORE kind=t3 filter.
                // reddit_filtered_nonpost: non-t3 entries (subreddits/users) that consumed
                //   cursor positions but were not stored. Sum this across all batches to
                //   explain any gap between Reddit's reported total and stored count.
                'reddit_children_total'  => $totalChildrenReddit,
                'reddit_filtered_nonpost'=> $filteredNonPost,
                'reddit_t3_returned'     => count($posts),
                'reddit_stored'          => $redditStored,
                'reddit_duplicates'      => $duplicates,
                'reddit_skipped'         => $skipped,
                'google_stored'          => $googleStored,
                'is_fully_synced'        => $isFullySynced,
                'total_fetched'          => $search->total_fetched,
            ]);

            // Emit an explicit completion log when all available Reddit posts have been
            // fetched. This appears in the log as a single authoritative summary line.
            if ($isFullySynced) {
                Log::info('FetchKeywordResultsJob: pagination complete — after=null received', [
                    'search_id'    => $this->searchId,
                    'keyword'      => $search->keyword,
                    'total_fetched'=> $search->total_fetched,
                    'batches'      => $search->getCurrentBatch(),
                    'after_in'     => $afterToken,
                    'final_api_url'=> $actualApiUrl,
                ]);
            }

        } catch (Throwable $exception) {
            // Do NOT set status='failed' on each individual attempt. Setting it here means
            // the UI shows "failed" during the 30s/60s/120s backoff delays between Laravel's
            // automatic retries. Users see the Retry button and click it, dispatching a new
            // job while the original retries are still queued — two concurrent jobs race on
            // the same reddit_after token.
            //
            // Status stays 'running' during all auto-retries. Only failed() below sets it
            // to 'failed', once, after all $tries are exhausted.

            Log::error('FetchKeywordResultsJob: attempt failed', [
                'search_id'  => $this->searchId,
                'keyword'    => $search->keyword,
                'batch'      => $batchNumber,
                'after_in'   => $afterToken,
                'attempt'    => $this->attempts(),
                'tries_left' => $this->tries - $this->attempts(),
                'exception'  => get_class($exception),
                'message'    => $exception->getMessage(),
            ]);

            throw $exception;   // Let Laravel retry with backoff()
        }
    }

    /**
     * Called by Laravel after all $tries attempts are exhausted.
     * This is the ONLY place that sets status='failed' — exactly once, when the job
     * has truly given up — so the Retry button appears only when it should.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('FetchKeywordResultsJob: all retries exhausted', [
            'search_id' => $this->searchId,
            'exception' => get_class($exception),
            'message'   => $exception->getMessage(),
        ]);

        // Guard: never downgrade a batch that already completed successfully.
        DB::table('searches')
            ->where('id', $this->searchId)
            ->where('status', '!=', 'completed')
            ->update(['status' => 'failed']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function dispatchDetailJobs(\Illuminate\Support\Collection $results, int $searchId): void
    {
        $dispatched = 0;

        $results
            ->filter(fn (Result $r) => $r->source === 'reddit' && filled($r->permalink))
            ->each(function (Result $result) use ($searchId, &$dispatched) {
                FetchRedditPostDetailJob::dispatch(
                    $result->id,
                    $searchId,
                    $result->permalink,
                );
                $dispatched++;
            });

        if ($dispatched > 0) {
            Log::info('FetchKeywordResultsJob: detail jobs dispatched', [
                'search_id'  => $searchId,
                'dispatched' => $dispatched,
            ]);
        }
    }

    /**
     * Clean Reddit selftext — remove HTML entities and the "submitted by /u/..."
     * footer that appears in RSS-sourced content.
     */
    private function cleanSelftext(string $raw): ?string
    {
        if ($raw === '' || in_array($raw, ['[deleted]', '[removed]'], true)) {
            return null;
        }

        $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = trim(preg_replace('/\s*submitted by\s.+$/su', '', $text));

        return $text ?: null;
    }

    // -------------------------------------------------------------------------
    // Google Custom Search API (optional — requires API credentials in .env)
    // -------------------------------------------------------------------------

    private function searchGoogle(string $keyword, int $limit): array
    {
        $apiKey   = config('services.google.search_api_key');
        $engineId = config('services.google.search_engine_id');

        if (! $apiKey || ! $engineId) {
            return [];
        }

        $starts = range(1, min(91, (int) ceil($limit / 10) * 10 - 9), 10);

        try {
            $responses = Http::pool(function (Pool $pool) use ($apiKey, $engineId, $keyword, $starts) {
                return collect($starts)
                    ->map(fn (int $start) => $pool
                        ->timeout(10)
                        ->connectTimeout(5)
                        ->get('https://www.googleapis.com/customsearch/v1', [
                            'key'   => $apiKey,
                            'cx'    => $engineId,
                            'q'     => $keyword,
                            'num'   => 10,
                            'start' => $start,
                        ]))
                    ->all();
            });
        } catch (Throwable $e) {
            Log::warning('Google search pool failed', ['error' => $e->getMessage()]);
            return [];
        }

        $results = [];

        foreach ($responses as $response) {
            if (! $response->successful()) {
                continue;
            }
            foreach ($response->json('items', []) as $item) {
                $results[] = [
                    'external_id' => $item['cacheId'] ?? ('google_' . md5($item['link'] ?? '')),
                    'title'       => Str::limit($item['title'] ?? 'Untitled', 255, ''),
                    'url'         => $item['link'] ?? '#',
                ];
                if (count($results) >= $limit) break 2;
            }
        }

        return array_slice($results, 0, $limit);
    }
}
