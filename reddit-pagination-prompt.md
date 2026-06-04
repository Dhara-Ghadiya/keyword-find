# Laravel 12 Reddit Pagination Enhancement — Complete Implementation Prompt

## Project Context

**Project Path:** `D:\wamp64\www\keyword-find\keyword-find`

Read and fully analyze the existing project before writing any code. Review:
- All migrations and current database schema
- All models and their relationships
- All service classes (especially Reddit-related)
- All queue jobs
- All controllers related to search
- All routes
- Views related to search

Do NOT rebuild existing functionality. Extend it cleanly.

---

## Existing Functionality (Do Not Break)

Already implemented:
- User submits keyword → stored in `searches` table
- Reddit Search API called → results stored in `results` table
- Detail post data stored in `reddit_posts` table
- Queue processing already implemented

Existing relationships:
```
searches     → hasMany(results)
searches     → hasMany(reddit_posts)
results      → belongsTo(searches)
results      → hasOne(reddit_posts)
reddit_posts → belongsTo(searches)
reddit_posts → belongsTo(results)
```

---

## Core Requirement

**Every time the user searches the same keyword, fetch the NEXT 25 Reddit posts — not the same ones again.**

| Search Attempt | Results Range | API Behavior |
|---|---|---|
| 1st | 1–25   | No `after` param |
| 2nd | 26–50  | `after=t3_abc123` |
| 3rd | 51–75  | `after=t3_xyz456` |
| nth | Continues | Until `after=null` |

When Reddit returns `after=null`, mark keyword as fully synced and stop paginating.

---

## Step 1 — Database Migration

Check the existing `searches` table migration first. Only add columns that do NOT already exist.

Create migration: `add_pagination_fields_to_searches_table`

Add to `searches` table:
```php
$table->string('reddit_after')->nullable()->after('keyword');  // Latest Reddit pagination token
$table->timestamp('last_synced_at')->nullable()->after('reddit_after'); // Last successful sync time
$table->boolean('is_fully_synced')->default(false)->after('last_synced_at'); // True when after=null returned
$table->unsignedInteger('total_fetched')->default(0)->after('is_fully_synced'); // Total posts fetched so far
```

Also check `results` table — add if missing:
```php
$table->unsignedInteger('batch_number')->default(1)->after('search_id'); // Which fetch batch (1=first 25, 2=second 25, etc.)
```

Add indexes:
```php
// In searches migration
$table->index(['keyword', 'user_id']); // Fast lookup by keyword per user

// In results migration  
$table->index(['search_id', 'batch_number']); // Fast batch-based queries
```

---

## Step 2 — Update Search Model

File: `app/Models/Search.php`

Add fillable fields:
```php
protected $fillable = [
    // ... existing fields ...
    'reddit_after',
    'last_synced_at',
    'is_fully_synced',
    'total_fetched',
];
```

Add casts:
```php
protected $casts = [
    // ... existing casts ...
    'last_synced_at' => 'datetime',
    'is_fully_synced' => 'boolean',
    'total_fetched' => 'integer',
];
```

Add helper methods:
```php
/**
 * Check if this search has a pagination token ready for next fetch.
 */
public function hasMoreResults(): bool
{
    return !$this->is_fully_synced && ($this->reddit_after !== null || $this->total_fetched === 0);
}

/**
 * Get the current batch number (which page we're on).
 */
public function getCurrentBatch(): int
{
    return (int) ceil($this->total_fetched / 25);
}

/**
 * Get the next batch number.
 */
public function getNextBatch(): int
{
    return $this->getCurrentBatch() + 1;
}
```

---

## Step 3 — Reddit Service Update

File: `app/Services/RedditService.php` (or wherever Reddit API calls live — review first)

### Critical Rule
All API calls for the same keyword MUST use `sort=new` consistently. If sort changes, Reddit's `after` token becomes invalid.

### Update the search method signature:
```php
public function searchPosts(string $keyword, ?string $afterToken = null): array
```

### Build URL correctly:
```php
$params = [
    'q'     => $keyword,
    'limit' => 25,
    'sort'  => 'new',  // ALWAYS 'new' — never change this for same keyword
];

if ($afterToken !== null) {
    $params['after'] = $afterToken;
}

$url = 'https://www.reddit.com/search.json?' . http_build_query($params);
```

### Use Laravel HTTP Client (no cURL):
```php
$response = Http::timeout(10)
    ->retry(3, 1000)
    ->withHeaders([
        'User-Agent' => 'KeywordFind/1.0 (Laravel App)',
    ])
    ->get($url);

if ($response->failed()) {
    Log::error('Reddit API failed', [
        'keyword'    => $keyword,
        'after'      => $afterToken,
        'status'     => $response->status(),
        'response'   => $response->body(),
    ]);
    throw new \Exception('Reddit API request failed: ' . $response->status());
}

$data = $response->json();

return [
    'posts'       => $data['data']['children'] ?? [],
    'after'       => $data['data']['after'] ?? null,    // null means no more pages
    'before'      => $data['data']['before'] ?? null,
    'total_found' => count($data['data']['children'] ?? []),
];
```

### Add logging for pagination:
```php
Log::info('Reddit API called', [
    'keyword'       => $keyword,
    'after_used'    => $afterToken,
    'after_returned'=> $data['data']['after'] ?? null,
    'posts_returned'=> count($data['data']['children'] ?? []),
]);
```

---

## Step 4 — Controller Update

File: Review and update the existing search controller (find it — do not assume the name).

### Search Store Method Logic:

```php
public function store(Request $request)
{
    $request->validate([
        'keyword' => 'required|string|min:2|max:255',
    ]);

    $keyword = trim($request->input('keyword'));

    // Find existing search record for this user+keyword
    $search = Search::where('keyword', $keyword)
        ->where('user_id', auth()->id())  // Remove this line if app has no auth
        ->first();

    // If fully synced — no more Reddit data available
    if ($search && $search->is_fully_synced) {
        return back()->with('info', 'All available Reddit posts for "' . $keyword . '" have been fetched (' . $search->total_fetched . ' total). Reddit has no more results.');
    }

    // If searched too recently (rate limit protection — optional, adjust as needed)
    if ($search && $search->last_synced_at && $search->last_synced_at->diffInMinutes(now()) < 1) {
        return back()->with('warning', 'Please wait before searching "' . $keyword . '" again.');
    }

    // Create or get search record
    if (!$search) {
        $search = Search::create([
            'keyword'    => $keyword,
            'user_id'    => auth()->id(),  // Remove if no auth
            'reddit_after'     => null,
            'is_fully_synced'  => false,
            'total_fetched'    => 0,
        ]);
    }

    // Dispatch queue job — pass the search ID and current after token
    ProcessRedditSearch::dispatch($search->id);

    return redirect()->route('searches.show', $search->id)
        ->with('success', 'Fetching next batch of Reddit posts for "' . $keyword . '"...');
}
```

### Searches Index Method (with pagination):

```php
public function index()
{
    $searches = Search::where('user_id', auth()->id())  // Remove if no auth
        ->withCount('results')
        ->latest()
        ->paginate(15);  // 15 searches per page

    return view('searches.index', compact('searches'));
}
```

### Search Show Method:

```php
public function show(Search $search)
{
    $results = $search->results()
        ->with('redditPost')
        ->orderBy('batch_number')
        ->orderBy('id')
        ->paginate(25);  // Show 25 results per page

    return view('searches.show', compact('search', 'results'));
}
```

---

## Step 5 — Queue Job Update

File: Review and update the existing queue job (find it — do not assume the name). It may be called `ProcessRedditSearch` or similar.

### Full job implementation:

```php
<?php

namespace App\Jobs;

use App\Models\Search;
use App\Services\RedditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRedditSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public readonly int $searchId)
    {
    }

    public function handle(RedditService $redditService): void
    {
        // Lock the search record to prevent race conditions
        // (if user submits same keyword twice quickly)
        $search = DB::transaction(function () {
            return Search::lockForUpdate()->find($this->searchId);
        });

        if (!$search) {
            Log::warning('ProcessRedditSearch: Search not found', ['search_id' => $this->searchId]);
            return;
        }

        if ($search->is_fully_synced) {
            Log::info('ProcessRedditSearch: Already fully synced', ['search_id' => $this->searchId, 'keyword' => $search->keyword]);
            return;
        }

        $currentBatch = $search->getNextBatch();
        $afterToken   = $search->reddit_after; // null on first call

        Log::info('ProcessRedditSearch: Starting', [
            'search_id' => $this->searchId,
            'keyword'   => $search->keyword,
            'batch'     => $currentBatch,
            'after'     => $afterToken,
        ]);

        try {
            // Call Reddit API
            $apiResult = $redditService->searchPosts($search->keyword, $afterToken);

            $posts      = $apiResult['posts'];
            $newAfter   = $apiResult['after'];

            if (empty($posts)) {
                Log::info('ProcessRedditSearch: No posts returned', ['search_id' => $this->searchId]);
                $search->update([
                    'is_fully_synced' => true,
                    'last_synced_at'  => now(),
                ]);
                return;
            }

            // Store results — with duplicate prevention
            $stored = 0;
            foreach ($posts as $post) {
                $postData = $post['data'] ?? [];

                if (empty($postData['id'])) {
                    continue;
                }

                // Duplicate prevention using reddit_post_id
                $result = $search->results()->firstOrCreate(
                    ['reddit_post_id' => $postData['id']], // unique key
                    [
                        'title'         => $postData['title'] ?? '',
                        'url'           => $postData['url'] ?? '',
                        'permalink'     => 'https://reddit.com' . ($postData['permalink'] ?? ''),
                        'score'         => $postData['score'] ?? 0,
                        'num_comments'  => $postData['num_comments'] ?? 0,
                        'subreddit'     => $postData['subreddit'] ?? '',
                        'author'        => $postData['author'] ?? '',
                        'batch_number'  => $currentBatch,
                        // ... add any other fields that exist in your results table
                    ]
                );

                if ($result->wasRecentlyCreated) {
                    $stored++;
                    // Dispatch detail job for new results only
                    // (Review what your existing detail job is called)
                    // FetchRedditPostDetail::dispatch($result->id);
                }
            }

            // Update search record
            $search->update([
                'reddit_after'    => $newAfter,   // null if no more pages
                'is_fully_synced' => $newAfter === null,
                'last_synced_at'  => now(),
                'total_fetched'   => $search->total_fetched + $stored,
            ]);

            Log::info('ProcessRedditSearch: Batch complete', [
                'search_id'    => $this->searchId,
                'batch'        => $currentBatch,
                'stored'       => $stored,
                'new_after'    => $newAfter,
                'fully_synced' => $newAfter === null,
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessRedditSearch: Failed', [
                'search_id' => $this->searchId,
                'keyword'   => $search->keyword,
                'error'     => $e->getMessage(),
            ]);

            throw $e; // Let Laravel retry the job
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessRedditSearch: Job permanently failed', [
            'search_id' => $this->searchId,
            'error'     => $exception->getMessage(),
        ]);
    }
}
```

---

## Step 6 — Duplicate Prevention

Ensure `results` table has a unique index on `reddit_post_id`:

```php
// In a migration
$table->unique(['search_id', 'reddit_post_id']); // Same post can't appear twice for same search
```

In the job, use `firstOrCreate()` as shown above — never `create()` directly.

For `reddit_posts` table, also ensure:
```php
$table->unique('reddit_post_id'); // Or 'permalink' — whichever you currently use
```

Use `updateOrCreate()` for reddit_posts detail records:
```php
RedditPost::updateOrCreate(
    ['reddit_post_id' => $postData['id']],
    [/* post detail fields */]
);
```

---

## Step 7 — Searches Index View (with Pagination)

Update the searches index Blade view (`resources/views/searches/index.blade.php`).

Show each search with:
- Keyword
- Total results fetched so far (`total_fetched`)
- Current status: "Fetching...", "25 results", "50 results", "Fully Synced"
- "Search More" button (disabled if `is_fully_synced`)
- Created at date

Example status logic in Blade:
```blade
@if($search->is_fully_synced)
    <span class="badge bg-success">Fully Synced ({{ $search->total_fetched }} posts)</span>
@elseif($search->total_fetched > 0)
    <span class="badge bg-info">{{ $search->total_fetched }} posts — More available</span>
@else
    <span class="badge bg-secondary">Pending</span>
@endif
```

Pagination links at bottom:
```blade
{{ $searches->links() }}
```

---

## Step 8 — Search Show View

Update `resources/views/searches/show.blade.php`.

Show:
- Keyword as heading
- Batch info: "Showing results 1–25", "Showing results 26–50", etc.
- "Fetch Next 25 Posts" button (POST to search store, passing keyword)
- If `is_fully_synced`: show "All Reddit posts fetched" message instead of button
- Results table with pagination

---

## Step 9 — Final Checklist

After implementation, verify:

- [ ] First search fetches posts 1–25 with no `after` param
- [ ] Second search for same keyword fetches posts 26–50 using stored token
- [ ] `batch_number` increments correctly (1, 2, 3...)
- [ ] Duplicate posts are never inserted
- [ ] When Reddit returns `after=null`, `is_fully_synced=true`
- [ ] "Search More" button is disabled/hidden when fully synced
- [ ] Queue job handles race conditions (lockForUpdate)
- [ ] Searches index page shows pagination (15 per page)
- [ ] All logs are correct and useful
- [ ] No existing functionality is broken

---

## Important Technical Notes

1. **Sort consistency**: Always use `sort=new` — never change it between calls for the same keyword. Reddit's `after` token is tied to the sort parameter.

2. **Race condition**: The `DB::transaction + lockForUpdate` in the job prevents two simultaneous jobs from calling the same `after` token if the user submits quickly.

3. **User-Agent header**: Reddit requires a User-Agent or may rate-limit/block requests.

4. **batch_number**: Starts at 1 for first 25 results, 2 for next 25, etc. This lets you display "Results 1–25", "Results 26–50" in the UI.

5. **total_fetched** tracks cumulative posts stored (not API posts returned, since duplicates are skipped).
