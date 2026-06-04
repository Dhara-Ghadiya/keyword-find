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

    public int $tries   = 3;
    public int $timeout = 300;

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

        Log::info('FetchKeywordResultsJob: starting batch', [
            'search_id'   => $this->searchId,
            'keyword'     => $search->keyword,
            'batch'       => $batchNumber,
            'after_token' => $afterToken,
        ]);

        try {
            // ── Reddit API (paginated, sort=new) ──────────────────────────────
            $apiResult = $reddit->searchPosts($search->keyword, $afterToken);

            $posts    = $apiResult['posts'];
            $newAfter = $apiResult['after'];

            if (empty($posts)) {
                Log::info('FetchKeywordResultsJob: no posts returned — marking fully synced', [
                    'search_id' => $this->searchId,
                    'batch'     => $batchNumber,
                ]);
                $search->update([
                    'status'          => $search->total_fetched > 0 ? 'completed' : 'no_results',
                    'is_fully_synced' => true,
                    'last_synced_at'  => now(),
                ]);
                return;
            }

            // ── Store Reddit results ──────────────────────────────────────────
            $stored       = 0;
            $savedResults = collect();

            foreach ($posts as $post) {
                // searchPosts() returns normalized flat arrays (no 'data' wrapper)
                $redditId  = $post['id'] ?? null;
                $permalink = $post['permalink'] ?? null;

                // Skip if no ID or not an actual post (subreddit links — safety guard)
                if (! $redditId || ! str_contains($permalink ?? '', '/comments/')) {
                    continue;
                }

                $selftext = $this->cleanSelftext($post['selftext'] ?? '');

                // firstOrCreate prevents duplicate results across batches
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
                    $stored++;
                    $savedResults->push($result);
                }
            }

            // ── Google results (first batch only — Google has no after-token pagination) ──
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
                        $stored++;
                    }
                }
            }

            // ── Update search pagination state ────────────────────────────────
            $isFullySynced = $newAfter === null;

            $search->update([
                'status'          => 'completed',
                'reddit_after'    => $newAfter,
                'is_fully_synced' => $isFullySynced,
                'last_synced_at'  => now(),
                'total_fetched'   => $search->total_fetched + $stored,
            ]);

            // ── Dispatch detail-fetch jobs for new Reddit results ─────────────
            $this->dispatchDetailJobs($savedResults, $search->id);

            Log::info('FetchKeywordResultsJob: batch complete', [
                'search_id'    => $this->searchId,
                'keyword'      => $search->keyword,
                'batch'        => $batchNumber,
                'stored'       => $stored,
                'new_after'    => $newAfter,
                'fully_synced' => $isFullySynced,
                'total_fetched'=> $search->total_fetched + $stored,
            ]);

        } catch (Throwable $exception) {
            $search->update(['status' => 'failed']);

            Log::error('FetchKeywordResultsJob failed', [
                'search_id' => $this->searchId,
                'keyword'   => $search->keyword,
                'batch'     => $batchNumber,
                'message'   => $exception->getMessage(),
            ]);

            throw $exception;   // Let Laravel retry the job
        }
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
