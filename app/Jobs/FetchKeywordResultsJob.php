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
 * Fetches one batch (up to 25) of Reddit posts for a keyword.
 *
 * Pagination contract:
 *   Each user search dispatches one instance of this job.
 *   The job reads reddit_after from the searches row, fetches the next page,
 *   stores up to TARGET_BATCH_SIZE new results, and writes the updated cursor back.
 *   Pagination ends when Reddit returns after = null.
 *
 * Multi-page fill loop:
 *   If duplicates reduce the stored count below TARGET_BATCH_SIZE, the job
 *   fetches up to MAX_EXTRA_PAGES additional Reddit pages within the same run.
 *
 * Google Custom Search is fetched only on the first batch (no pagination).
 */
class FetchKeywordResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 7;
    public int $timeout = 300;

    private const TARGET_BATCH_SIZE = 25;
    private const MAX_EXTRA_PAGES   = 5;

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 600];
    }

    public function __construct(
        public readonly int $searchId
    ) {}

    // -------------------------------------------------------------------------
    // Main handler
    // -------------------------------------------------------------------------

    public function handle(RedditService $reddit): void
    {
        // ── Retry safety: reset stuck 'running' rows ──────────────────────────
        // If attempt 1 was killed (OOM, SIGKILL) before the catch block could
        // reset the status, the row stays 'running'. Reset it on retry so the
        // claim below can succeed.
        if ($this->attempts() > 1) {
            DB::table('searches')
                ->where('id', $this->searchId)
                ->where('reddit_sync_status', 'running')
                ->update(['reddit_sync_status' => 'queued']);
        }

        // ── Atomic batch claim ────────────────────────────────────────────────
        // One atomic UPDATE ensures only one concurrent job proceeds.
        // Allow re-run when reddit_after is non-null (more pages exist), even if
        // reddit_sync_status is already 'completed' (previous batch done, not final).
        $claimed = DB::table('searches')
            ->where('id', $this->searchId)
            ->where('reddit_sync_status', '!=', 'running')
            ->where(function ($q) {
                $q->whereNotIn('reddit_sync_status', ['completed', 'no_results'])
                  ->orWhereNotNull('reddit_after');
            })
            ->update(['reddit_sync_status' => 'running']);

        if ($claimed === 0) {
            Log::info('FetchKeywordResultsJob: skipped — already running or fully done', [
                'search_id' => $this->searchId,
                'attempt'   => $this->attempts(),
            ]);
            return;
        }

        $search = Search::find($this->searchId);

        if (! $search) {
            Log::warning('FetchKeywordResultsJob: search not found after claim', [
                'search_id' => $this->searchId,
            ]);
            DB::table('searches')
                ->where('id', $this->searchId)
                ->where('reddit_sync_status', 'running')
                ->update(['reddit_sync_status' => 'failed']);
            return;
        }

        $batchNumber  = $search->getNextBatch();
        $afterToken   = $search->reddit_after;
        $isFirstBatch = $batchNumber === 1;

        // Strip user-typed quote chars; RedditService re-wraps the phrase in double
        // quotes for exact-phrase API search: q="linkedin automation" on Reddit.
        $cleanKeyword = str_replace('"', '', $search->keyword);

        $expectedApiUrl = 'https://www.reddit.com/search.json?' . http_build_query(
            array_filter([
                'q'     => '"' . $cleanKeyword . '"',
                'limit' => 25,
                'sort'  => 'new',
                't'     => 'all',
                'after' => $afterToken,
            ])
        );

        Log::info('FetchKeywordResultsJob: starting batch', [
            'search_id'        => $this->searchId,
            'keyword'          => $search->keyword,
            'clean_keyword'    => $cleanKeyword,
            'batch'            => $batchNumber,
            'after_token_in'   => $afterToken,
            'expected_api_url' => $expectedApiUrl,
            'total_fetched'    => $search->total_fetched,
        ]);

        try {
            // ── Multi-page fill loop ──────────────────────────────────────────
            $redditStored        = 0;
            $savedResults        = collect();
            $duplicates          = 0;
            $skipped             = 0;
            $keywordFiltered     = 0;
            $totalChildrenReddit = 0;
            $filteredNonPost     = 0;
            $totalPostsReturned  = 0;
            $apiPagesConsumed    = 0;
            $actualApiUrl        = $expectedApiUrl;

            $currentAfter = $afterToken;
            $newAfter     = null; // set before every break path

            while (true) {
                $apiResult = $reddit->searchPosts($cleanKeyword, $currentAfter);
                $apiPagesConsumed++;

                $rawPosts  = $apiResult['posts'];
                $pageAfter = $apiResult['after'];

                $totalChildrenReddit += $apiResult['total_children']  ?? count($rawPosts);
                $filteredNonPost     += $apiResult['filtered_nonpost'] ?? 0;
                $totalPostsReturned  += count($rawPosts);

                // Phrase filter — secondary safety net after exact-phrase API search.
                // Drops any post whose title AND body both lack the keyword phrase.
                $phrase    = mb_strtolower($cleanKeyword);
                $posts     = array_values(array_filter(
                    $rawPosts,
                    fn (array $p) => str_contains(mb_strtolower($p['title'] ?? ''), $phrase)
                                  || str_contains(mb_strtolower($p['selftext'] ?? ''), $phrase)
                ));
                $keywordFiltered += count($rawPosts) - count($posts);

                if ($apiPagesConsumed === 1) {
                    $actualApiUrl = $apiResult['api_url'] ?? $expectedApiUrl;
                    Log::info('FetchKeywordResultsJob: Reddit API request', [
                        'search_id'          => $this->searchId,
                        'url'                => $actualApiUrl,
                        'keyword'            => $cleanKeyword,
                        'after_in'           => $currentAfter,
                        'returned'           => count($rawPosts),
                        'after_phrase_filter'=> count($posts),
                    ]);
                }

                // ── Empty page ────────────────────────────────────────────────
                if (empty($posts)) {
                    if ($apiPagesConsumed === 1) {
                        if ($pageAfter !== null) {
                            // Empty page but more exist — advance cursor, not done yet
                            Log::warning('FetchKeywordResultsJob: empty page with non-null after — advancing cursor', [
                                'search_id' => $this->searchId,
                                'batch'     => $batchNumber,
                                'after_in'  => $afterToken,
                                'after_out' => $pageAfter,
                            ]);
                            DB::table('searches')->where('id', $this->searchId)->update([
                                'reddit_sync_status' => 'completed',
                                'reddit_after'       => $pageAfter,
                            ]);
                        } else {
                            // Reddit exhausted — mark fully done
                            $this->markExhausted($search);
                        }
                        return;
                    }
                    // Extra page empty — take what we have
                    $newAfter = $pageAfter; // null = done, non-null = skip ahead
                    break;
                }

                // ── Store Reddit results ──────────────────────────────────────
                foreach ($posts as $post) {
                    $redditId  = $post['id'] ?? null;
                    $permalink = $post['permalink'] ?? null;

                    if (! $redditId || ! str_contains($permalink ?? '', '/comments/')) {
                        $skipped++;
                        continue;
                    }

                    $selftext = $this->cleanSelftext($post['selftext'] ?? '');

                    try {
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
                    } catch (\Illuminate\Database\QueryException $e) {
                        if (($e->errorInfo[1] ?? 0) === 1062) {
                            $duplicates++;
                            continue;
                        }
                        throw $e;
                    }

                    if ($result->wasRecentlyCreated) {
                        $redditStored++;
                        $savedResults->push($result);

                        if ($redditStored >= self::TARGET_BATCH_SIZE) {
                            $newAfter = 't3_' . $redditId;
                            break; // break foreach only
                        }
                    } else {
                        $duplicates++;
                    }
                }

                // ── Post-page break conditions ────────────────────────────────
                if ($redditStored >= self::TARGET_BATCH_SIZE) {
                    break; // $newAfter set inside foreach
                }

                if ($pageAfter === null) {
                    $newAfter = null;
                    break;
                }

                if ($apiPagesConsumed > self::MAX_EXTRA_PAGES) {
                    $newAfter = $pageAfter;
                    Log::warning('FetchKeywordResultsJob: extra page limit reached — saving partial batch', [
                        'search_id'  => $this->searchId,
                        'batch'      => $batchNumber,
                        'pages'      => $apiPagesConsumed,
                        'stored'     => $redditStored,
                        'duplicates' => $duplicates,
                    ]);
                    break;
                }

                Log::info('FetchKeywordResultsJob: fetching extra page to fill batch', [
                    'search_id'  => $this->searchId,
                    'batch'      => $batchNumber,
                    'pages_done' => $apiPagesConsumed,
                    'stored'     => $redditStored,
                    'need_more'  => self::TARGET_BATCH_SIZE - $redditStored,
                ]);

                $currentAfter = $pageAfter;
            }

            // ── Google results (first batch only) ─────────────────────────────
            $googleStored = 0;
            if ($isFirstBatch) {
                foreach ($this->searchGoogle($search->keyword, 25) as $googleResult) {
                    try {
                        $result = $search->results()->firstOrCreate(
                            ['external_id' => $googleResult['external_id']],
                            [
                                'batch_number' => $batchNumber,
                                'source'       => 'google',
                                'permalink'    => null,
                                'title'        => $googleResult['title'],
                                'url'          => $googleResult['url'],
                                'selftext'     => null,
                            ]
                        );
                    } catch (\Illuminate\Database\QueryException $e) {
                        if (($e->errorInfo[1] ?? 0) === 1062) {
                            continue;
                        }
                        throw $e;
                    }

                    if ($result->wasRecentlyCreated) {
                        $googleStored++;
                    }
                }
            }

            // ── Update pagination state ───────────────────────────────────────
            $isDone = $newAfter === null;

            DB::table('searches')->where('id', $this->searchId)->update([
                'reddit_sync_status' => $isDone
                    ? ($redditStored + $search->total_fetched > 0 ? 'completed' : 'no_results')
                    : 'completed',
                'reddit_after'  => $newAfter,
                'total_fetched' => DB::raw('total_fetched + ' . $redditStored),
            ]);

            $this->dispatchDetailJobs($savedResults, $search->id);

            $search->refresh();

            Log::info('FetchKeywordResultsJob: batch complete', [
                'search_id'               => $this->searchId,
                'keyword'                 => $search->keyword,
                'batch'                   => $batchNumber,
                'api_url'                 => $actualApiUrl,
                'after_in'                => $afterToken,
                'after_out'               => $newAfter,
                'api_pages_consumed'      => $apiPagesConsumed,
                'reddit_children_total'   => $totalChildrenReddit,
                'reddit_filtered_nonpost' => $filteredNonPost,
                'reddit_t3_returned'      => $totalPostsReturned,
                'reddit_keyword_filtered' => $keywordFiltered,
                'reddit_stored'           => $redditStored,
                'reddit_duplicates'       => $duplicates,
                'reddit_skipped'          => $skipped,
                'google_stored'           => $googleStored,
                'is_done'                 => $isDone,
                'total_fetched'           => $search->total_fetched,
            ]);

            if ($isDone) {
                Log::info('FetchKeywordResultsJob: Reddit pagination complete — after=null received', [
                    'search_id'    => $this->searchId,
                    'keyword'      => $search->keyword,
                    'total_fetched'=> $search->total_fetched,
                    'batches'      => $search->getCurrentBatch(),
                ]);
            }

        } catch (Throwable $exception) {
            // Reset to queued so Laravel's auto-retry can re-claim the row.
            DB::table('searches')
                ->where('id', $this->searchId)
                ->where('reddit_sync_status', 'running')
                ->update(['reddit_sync_status' => 'queued']);

            Log::error('FetchKeywordResultsJob: attempt failed — reset to queued for retry', [
                'search_id'  => $this->searchId,
                'attempt'    => $this->attempts(),
                'tries_left' => $this->tries - $this->attempts(),
                'exception'  => get_class($exception),
                'message'    => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    // -------------------------------------------------------------------------
    // Permanent failure hook
    // -------------------------------------------------------------------------

    public function failed(Throwable $exception): void
    {
        Log::error('FetchKeywordResultsJob: all retries exhausted', [
            'search_id' => $this->searchId,
            'exception' => get_class($exception),
            'message'   => $exception->getMessage(),
        ]);

        DB::table('searches')
            ->where('id', $this->searchId)
            ->whereNotIn('reddit_sync_status', ['completed', 'no_results'])
            ->update(['reddit_sync_status' => 'failed']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function markExhausted(Search $search): void
    {
        $isDone = $search->total_fetched > 0;

        DB::table('searches')->where('id', $this->searchId)->update([
            'reddit_sync_status' => $isDone ? 'completed' : 'no_results',
            'reddit_after'       => null,
        ]);

        Log::info('FetchKeywordResultsJob: Reddit exhausted — after=null with empty page', [
            'search_id'    => $this->searchId,
            'keyword'      => $search->keyword,
            'total_fetched'=> $search->total_fetched,
        ]);
    }

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
    // Google Custom Search (optional — requires API credentials in .env)
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
            Log::warning('FetchKeywordResultsJob: Google search pool failed', [
                'search_id' => $this->searchId,
                'error'     => $e->getMessage(),
            ]);
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
                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        return array_slice($results, 0, $limit);
    }
}
