<?php

namespace App\Jobs;

use App\Models\Result;
use App\Models\Search;
use App\Services\RedditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches search results from two sources:
 *  1. Reddit search (search.json → paginated RSS fallback) — up to 100 results
 *  2. Google Custom Search API                             — up to 100 results (requires credentials)
 *
 * After storing results, dispatches FetchRedditPostDetailJob for every Reddit result.
 */
class FetchKeywordResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 120;

    public function __construct(
        public readonly int $searchId
    ) {}

    public function handle(RedditService $reddit): void
    {
        $search = Search::findOrFail($this->searchId);
        $search->update(['status' => 'running']);
        $search->results()->delete();

        try {
            $results = collect()
                ->merge($reddit->search($search->keyword, 100))
                ->merge($this->searchGoogle($search->keyword, 100))
                ->unique(fn (array $r) => Str::lower($r['source'] . '|' . $r['title'] . '|' . $r['url']))
                ->take(100)
                ->values();

            $savedResults = $this->storeResults($search->id, $results);

            $search->update([
                'status' => $savedResults->isEmpty() ? 'no_results' : 'completed',
            ]);

            // Dispatch background detail-fetch for every Reddit result that has a permalink
            $this->dispatchDetailJobs($savedResults, $search->id);

        } catch (Throwable $exception) {
            $search->update(['status' => 'failed']);

            Log::error('FetchKeywordResultsJob failed', [
                'search_id' => $search->id,
                'keyword'   => $search->keyword,
                'message'   => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    // -------------------------------------------------------------------------
    // Result storage
    // -------------------------------------------------------------------------

    private function storeResults(int $searchId, Collection $results): Collection
    {
        return $results->map(fn (array $r) => Result::create([
            'search_id'   => $searchId,
            'source'      => $r['source'],
            'external_id' => $r['external_id'] ?? null,
            'permalink'   => $r['permalink'] ?? null,
            'title'       => $r['title'],
            'url'         => $r['url'],
            'snippet'     => $r['snippet'] ?? null,
            'position'    => $r['position'] ?? null,
            'raw_data'    => $r['raw_data'] ?? null,
        ]));
    }

    // -------------------------------------------------------------------------
    // Dispatch detail jobs
    // -------------------------------------------------------------------------

    private function dispatchDetailJobs(Collection $savedResults, int $searchId): void
    {
        $savedResults
            ->filter(fn (Result $r) => $r->source === 'reddit' && filled($r->permalink))
            ->values()
            ->each(function (Result $result) use ($searchId) {
                FetchRedditPostDetailJob::dispatch(
                    $result->id,
                    $searchId,
                    $result->permalink,
                );
            });
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

        // Google allows start=1..91 (max 100 results across 10 pooled requests)
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

        $results  = [];
        $position = 1;

        foreach ($responses as $response) {
            if (! $response->successful()) {
                Log::warning('Google search request failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                continue;
            }

            foreach ($response->json('items', []) as $item) {
                $results[] = [
                    'source'      => 'google',
                    'external_id' => $item['cacheId'] ?? null,
                    'permalink'   => null,
                    'title'       => Str::limit($item['title'] ?? 'Untitled', 255, ''),
                    'url'         => $item['link'] ?? '#',
                    'snippet'     => $item['snippet'] ?? null,
                    'position'    => $position++,
                    'raw_data'    => $item,
                ];

                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        return array_slice($results, 0, $limit);
    }
}
