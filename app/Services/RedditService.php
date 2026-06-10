<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Handles all Reddit API interactions.
 *
 * Search strategy (in priority order):
 *  1. Public search.json  — returns full post data incl. ups/downs/author (blocked on some servers)
 *  2. Paginated search.rss — 4 pages × 25 results = 100 (always works, limited fields)
 *
 * Detail strategy:
 *  1. permalink.json  — full data (blocked on some servers)
 *  2. comments/id.rss — limited data, always works
 */
class RedditService
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /** Reason why the last fetchPostDetail() returned null. */
    private string $lastFailureReason = '';

    /** HTTP status code of the last Reddit API response (0 = connection error / not set). */
    private int $lastHttpStatus = 0;

    /** First 300 chars of the last Reddit API response body (for debug logging). */
    private string $lastResponseSnippet = '';

    // -------------------------------------------------------------------------
    // Paginated search (sort=new + after token) — used by FetchKeywordResultsJob
    // -------------------------------------------------------------------------

    /**
     * Fetch the next page (25) of Reddit posts for a keyword.
     *
     * Returns normalized flat post arrays — same shape whether JSON or RSS was used.
     * IMPORTANT: sort=new must NEVER change for the same keyword search session;
     * Reddit's `after` pagination token is coupled to the sort parameter.
     *
     * Strategy:
     *  1. search.json — full data (ups/downs/author). Falls back silently on 403/429.
     *  2. search.rss  — always works, limited fields (no ups/downs/author).
     *
     * @param  string|null $afterToken  Reddit fullname token (e.g. "t3_abc123"), null = first page
     * @return array{posts: array, after: string|null, before: string|null, total_found: int}
     * @throws \Exception on non-recoverable failures (used by job to mark search as failed)
     */
    public function searchPosts(string $keyword, ?string $afterToken = null): array
    {
        $result = $this->searchPostsViaJson($keyword, $afterToken);

        if ($result !== null) {
            return $result;
        }

        Log::info('Reddit searchPosts: JSON unavailable — falling back to RSS', [
            'keyword' => $keyword,
            'after'   => $afterToken,
        ]);

        return $this->searchPostsViaRss($keyword, $afterToken);
    }

    /**
     * Try search.json. Returns null on 403/429/connection error so caller falls back to RSS.
     *
     * IMPORTANT: `type=link` is intentionally OMITTED.
     * Reddit's search API treats `type=link` as "link-type submissions only" (posts with
     * external URLs). Self/text posts are excluded. For broad keywords like "opportunities"
     * or "ui/ux designer", the majority of posts ARE self/text posts. Using `type=link`
     * caps the result set at link-only posts and triggers `after=null` far too early,
     * causing is_fully_synced=true when hundreds of posts remain available.
     *
     * Without `type`, Reddit may include t5 (subreddit) or t2 (user) entries in the
     * children array. We filter to kind=t3 (post submissions) only before returning, so
     * those entries never reach the job. The pagination `after` token from `data.after`
     * remains valid regardless — it is position-based across all 25 raw children.
     */
    private function searchPostsViaJson(string $keyword, ?string $afterToken): ?array
    {
        $params = [
            'q'     => '"' . $keyword . '"',
            'limit' => 25,
            'sort'  => 'new',
            't'     => 'all',
            // type=link intentionally omitted — see docblock above
        ];
        if ($afterToken !== null) {
            $params['after'] = $afterToken;
        }

        $apiUrl = 'https://www.reddit.com/search.json?' . http_build_query($params);

        try {
            $response = $this->jsonClient(10)->get('https://www.reddit.com/search.json', $params);
        } catch (Throwable $e) {
            Log::warning('Reddit searchPosts JSON: connection failed — trying RSS', [
                'keyword' => $keyword,
                'after'   => $afterToken,
                'api_url' => $apiUrl,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }

        // 403 = CDN block, 429 = rate-limited — fall through to RSS silently
        if (in_array($response->status(), [403, 429], true) || $response->failed()) {
            Log::info('Reddit searchPosts JSON: blocked/failed — trying RSS', [
                'keyword' => $keyword,
                'after'   => $afterToken,
                'api_url' => $apiUrl,
                'status'  => $response->status(),
            ]);
            return null;
        }

        $data     = $response->json();
        $children = $data['data']['children'] ?? [];
        $newAfter = $data['data']['after'] ?? null;

        // Filter to kind=t3 (post submissions) only — without type=link, Reddit may include
        // t5 subreddits and t2 user entries.
        //
        // IMPORTANT: The after-token from data.after is position-based across ALL 25 children
        // (including any non-t3 entries). When non-t3 entries are present in a page:
        //   - The cursor still advances by 25 positions (correct — those slots are consumed)
        //   - We store fewer than 25 posts for that batch (the non-t3 positions are empty)
        //   - The "filtered_nonpost" count in the job log reveals this discrepancy
        //
        // If users see (e.g.) 240 stored vs 249 expected, check whether the sum of
        // filtered_nonpost across all batches equals the gap — that would confirm the
        // "missing" entries were subreddits/users, not actual posts.
        $t3Children      = collect($children)->filter(fn(array $c) => ($c['kind'] ?? '') === 't3');
        $totalChildren   = count($children);
        $filteredNonPost = $totalChildren - $t3Children->count();

        $posts = $t3Children->map(function (array $child) {
            $p        = $child['data'] ?? [];
            $selftext = trim($p['selftext'] ?? '');
            if (in_array($selftext, ['[deleted]', '[removed]'], true)) {
                $selftext = '';
            }

            return [
                'id'                    => $p['id'] ?? null,
                'permalink'             => isset($p['permalink'])
                    ? rtrim($p['permalink'], '/') . '/'
                    : null,
                'title'                 => $p['title'] ?? 'Untitled',
                'selftext'              => $selftext,
                'url'                   => $p['url'] ?? '#',
                'author'                => $p['author'] ?? null,
                'ups'                   => (int) ($p['ups'] ?? 0),
                'downs'                 => (int) ($p['downs'] ?? 0),
                'total_awards_received' => (int) ($p['total_awards_received'] ?? 0),
                'created_utc'           => (int) ($p['created_utc'] ?? 0),
            ];
        })->values()->all();

        Log::info('Reddit searchPosts JSON: success', [
            'keyword'           => $keyword,
            'api_url'           => $apiUrl,
            'after_in'          => $afterToken,
            'after_out'         => $newAfter,
            'total_children'    => $totalChildren,
            'filtered_nonpost'  => $filteredNonPost,
            'post_count'        => count($posts),
        ]);

        return [
            'posts'            => $posts,
            'after'            => $newAfter,
            'before'           => $data['data']['before'] ?? null,
            'total_found'      => count($posts),
            'total_children'   => $totalChildren,   // raw count before kind=t3 filter
            'filtered_nonpost' => $filteredNonPost, // non-t3 entries consumed by cursor
            'api_url'          => $apiUrl,
        ];
    }

    /**
     * Fetch posts via RSS, collecting across multiple pages until 25 posts are gathered.
     *
     * WHY MULTI-PAGE:
     * Reddit's RSS search mixes subreddit-home entries (no /comments/ URL) with actual
     * post entries. A single RSS page (limit=25 raw entries) may yield only 22–24 posts
     * after those subreddit entries are filtered. To guarantee a full 25-post batch this
     * method fetches up to 3 consecutive RSS pages and combines their filtered posts.
     *
     * AFTER-TOKEN STRATEGY:
     * We set `after` to the Reddit fullname (t3_<id>) of the 25th STORED post — NOT the
     * last raw entry. Using the last raw entry's position (which may be a subreddit) would
     * cause the next batch to re-encounter the same subreddit slots every time, making
     * every batch return only 22 posts forever. Using the 25th stored post's fullname
     * advances the cursor past those subreddit slots, ensuring each batch starts fresh.
     *
     * @throws \Exception on 403/429/connection errors (triggers job retry with backoff)
     */
    private function searchPostsViaRss(string $keyword, ?string $afterToken): array
    {
        $allPosts    = [];
        $fetchAfter  = $afterToken;
        $isFinalPage = false;
        $lastFullname = null;

        // Fetch up to 3 RSS pages to fill the batch to 25 posts.
        for ($fetch = 0; $fetch <= 2; $fetch++) {
            if ($fetch > 0) {
                usleep(500_000); // 0.5 s between consecutive RSS pages — avoids 429 bursts
            }
            [$rawEntries, $lastFullname] = $this->doRssRequest($keyword, $fetchAfter);

            // Keep only real posts; subreddit home-page links have no /comments/ in URL.
            $pagePosts = array_values(array_filter(
                $rawEntries,
                fn(array $e) => str_contains($e['permalink'] ?? '', '/comments/')
            ));

            $filtered = count($rawEntries) - count($pagePosts);
            if ($filtered > 0) {
                Log::info('Reddit searchPosts RSS: filtered non-post entries', [
                    'keyword'      => $keyword,
                    'fetch'        => $fetch + 1,
                    'after_used'   => $fetchAfter,
                    'raw'          => count($rawEntries),
                    'posts'        => count($pagePosts),
                    'filtered_out' => $filtered,
                ]);
            }

            // Merge and deduplicate by external_id immediately.
            // Reddit's RSS pagination can return the same post on two consecutive pages
            // when the cursor falls on a subreddit boundary. Without deduplication,
            // allPosts would contain the same post twice, and firstOrCreate would see
            // it first (INSERT) then see it again (SELECT → existing) — which is safe
            // but wastes a slot in the 25-post cap. Deduplicating here prevents any
            // duplicate from even reaching the job's storage loop.
            $allPosts = collect(array_merge($allPosts, $pagePosts))
                ->unique(fn(array $e) => $e['external_id'] ?? ($e['permalink'] ?? uniqid('', true)))
                ->values()
                ->all();

            $isFinalPage = count($rawEntries) < 25 || $lastFullname === null;

            if (count($allPosts) >= 25 || $isFinalPage) {
                break;
            }

            // Still under 25 posts — advance the cursor and fetch another page.
            $fetchAfter = $lastFullname;
            Log::info('Reddit searchPosts RSS: page returned fewer than 25 posts — fetching extra page', [
                'keyword'      => $keyword,
                'fetch_done'   => $fetch + 1,
                'posts_so_far' => count($allPosts),
                'next_after'   => $fetchAfter,
            ]);
        }

        // Cap at exactly 25 posts per batch.
        $posts = array_slice($allPosts, 0, 25);

        // Build the after token for the next job batch.
        // Use the fullname of the LAST POST WE ARE STORING (the 25th), not $lastFullname
        // from parseRssPage. If we stored 25 posts collected across two RSS pages, the
        // next batch must start after the 25th stored post — any carry-over posts from the
        // partial second page will be naturally re-fetched in the next batch via this cursor.
        if (count($posts) === 25) {
            $lastExternalId = $posts[24]['external_id'] ?? null;
            $newAfter       = $lastExternalId ? ('t3_' . $lastExternalId) : null;
        } elseif (! $isFinalPage) {
            // Safety net: hit the 3-page cap but pagination isn't finished.
            // Fall back to the last known t3_ cursor so the next batch continues.
            $newAfter = $lastFullname ?? null;
        } else {
            // Fewer than 25 posts exist in total — this is the last available batch.
            $newAfter = null;
        }

        $normalizedPosts = collect($posts)->map(fn(array $e) => [
            'id'                    => $e['external_id'] ?? null,
            'permalink'             => $e['permalink'] ?? null,
            'title'                 => $e['title'] ?? 'Untitled',
            'selftext'              => $e['selftext'] ?? '',
            'url'                   => $e['url'] ?? '#',
            'author'                => null,   // not available in search RSS
            'ups'                   => 0,
            'downs'                 => 0,
            'total_awards_received' => 0,
            'created_utc'           => 0,
        ])->all();

        $baseRssUrl = 'https://www.reddit.com/search.rss?' . http_build_query(
            array_filter(['q' => '"' . $keyword . '"', 'limit' => 25, 'sort' => 'new', 't' => 'all', 'after' => $afterToken])
        );

        Log::info('Reddit searchPosts RSS: success', [
            'keyword'       => $keyword,
            'api_url'       => $baseRssUrl,
            'after_in'      => $afterToken,
            'after_out'     => $newAfter,
            'pages_fetched' => $fetch + 1,
            'raw_collected' => count($allPosts),
            'stored'        => count($posts),
            'is_final_page' => $isFinalPage,
        ]);

        return [
            'posts'       => $normalizedPosts,
            'after'       => $newAfter,
            'before'      => null,
            'total_found' => count($normalizedPosts),
        ];
    }

    /**
     * Execute a single RSS HTTP request and return parsed entries.
     * Throws on all HTTP errors so the job's backoff() schedule handles retries.
     *
     * @return array{0: array, 1: string|null}  [rawEntries[], lastPostFullname|null]
     * @throws \Exception on connection error / 403 / 429 / non-2xx response
     */
    private function doRssRequest(string $keyword, ?string $afterToken): array
    {
        $params = [
            'q'     => '"' . $keyword . '"',
            'limit' => 25,
            'sort'  => 'new',
            't'     => 'all',
        ];
        if ($afterToken !== null) {
            $params['after'] = $afterToken;
        }

        $apiUrl = 'https://www.reddit.com/search.rss?' . http_build_query($params);

        try {
            $response = $this->rssClient()->get('https://www.reddit.com/search.rss', $params);
        } catch (Throwable $e) {
            Log::error('Reddit searchPosts RSS: connection failed', [
                'keyword' => $keyword,
                'after'   => $afterToken,
                'api_url' => $apiUrl,
                'error'   => $e->getMessage(),
            ]);
            throw new \Exception('Reddit RSS connection failed: ' . $e->getMessage());
        }

        // 403/429 are transient — throw so the job retries with its backoff() schedule.
        // Do NOT return empty — that would set after=null and mark the search fully synced
        // while Reddit still has pages of posts remaining.
        if (in_array($response->status(), [403, 429], true)) {
            Log::warning('Reddit searchPosts RSS: rate-limited or blocked — will retry', [
                'keyword' => $keyword,
                'after'   => $afterToken,
                'api_url' => $apiUrl,
                'status'  => $response->status(),
            ]);
            throw new \Exception(
                'Reddit RSS blocked (' . $response->status() . ') for keyword: ' . $keyword
            );
        }

        if ($response->failed()) {
            Log::error('Reddit searchPosts RSS: unexpected HTTP error', [
                'keyword' => $keyword,
                'after'   => $afterToken,
                'api_url' => $apiUrl,
                'status'  => $response->status(),
                'body'    => substr($response->body(), 0, 200),
            ]);
            throw new \Exception('Reddit RSS request failed: HTTP ' . $response->status());
        }

        return $this->parseRssPage($response->body());
    }

    // -------------------------------------------------------------------------
    // Public getters
    // -------------------------------------------------------------------------

    /** Human-readable reason why the last fetchPostDetail() returned null. */
    public function getLastFailureReason(): string
    {
        return $this->lastFailureReason;
    }

    /** HTTP status code of the last Reddit API response (0 = never set / connection error). */
    public function getLastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    /** First 300 chars of the last Reddit API response body, for debug logging. */
    public function getLastResponseSnippet(): string
    {
        return $this->lastResponseSnippet;
    }

    /**
     * Fetch detailed Reddit post data for a given permalink.
     * Tries public .json first; falls back to public RSS.
     *
     * @throws RuntimeException on RSS 429 — triggers job retry with backoff.
     * @return array<string,mixed>|null  null = post unavailable (see getLastFailureReason()).
     */
    public function fetchPostDetail(string $permalink): ?array
    {
        $this->lastFailureReason    = '';
        $this->lastHttpStatus       = 0;
        $this->lastResponseSnippet  = '';

        $data = $this->fetchDetailViaJson($permalink);

        if ($data !== null) {
            return $data;
        }

        $data = $this->fetchDetailViaRss($permalink);

        return $data;
    }

    /**
     * Parse one RSS page. Returns [entries[], lastFullname|null].
     * lastFullname is the Reddit "t3_ID" of the last entry — used as `after` for next page.
     */
    private function parseRssPage(string $body): array
    {
        $xml = @simplexml_load_string(preg_replace('/xmlns="[^"]*"/', '', $body));

        if (! $xml || empty($xml->entry)) {
            return [[], null];
        }

        $entries      = [];
        $lastFullname = null;

        foreach ($xml->entry as $entry) {
            $link      = $this->extractEntryLink($entry);
            $permalink = $link ? $this->urlToPermalink($link) : null;

            // id field looks like "t3_abc123"
            $idRaw = (string) ($entry->id ?? '');
            preg_match('/(t3_[a-z0-9]+)/i', $idRaw, $m);
            $fullname   = $m[1] ?? null;
            $externalId = $fullname ? str_replace('t3_', '', $fullname) : null;

            // Extract selftext from RSS content field:
            // 1. Decode HTML entities first (&#32; → space, &#39; → apostrophe, etc.)
            // 2. Strip HTML tags
            // 3. Remove Reddit's "submitted by /u/... [link] [comments]" footer
            // The order matters — regex uses \s which only matches after entity decoding.
            $raw      = html_entity_decode(
                (string) ($entry->content ?? $entry->summary ?? ''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $raw      = strip_tags($raw);
            $selftext = trim(preg_replace('/\s*submitted by\s.+$/su', '', $raw));

            $entries[] = [
                'source'      => 'reddit',
                'external_id' => $externalId,
                'permalink'   => $permalink,
                'title'       => Str::limit((string) ($entry->title ?? 'Untitled'), 255, ''),
                'url'         => $link ?: '#',
                'selftext'    => $selftext ?: null,
            ];

            // Only advance the pagination cursor when this entry IS a post (t3_ fullname).
            // Non-post entries (subreddits t5_, users t2_) produce $fullname = null here.
            // If we overwrote $lastFullname unconditionally, the final $lastFullname would
            // be null whenever the last raw RSS entry happens to be a subreddit — causing
            // $newAfter = null in the caller and a premature is_fully_synced = true flag
            // even when hundreds of Reddit posts remain unfetched.
            if ($fullname !== null) {
                $lastFullname = $fullname;
            }
        }

        return [$entries, $lastFullname];
    }

    // -------------------------------------------------------------------------
    // Detail fetch via JSON
    // -------------------------------------------------------------------------

    private function fetchDetailViaJson(string $permalink): ?array
    {
        $url = 'https://www.reddit.com' . $permalink . '.json';

        try {
            $response = $this->jsonClient()->get($url);
        } catch (Throwable $e) {
            $this->lastFailureReason = 'json_connection_error: ' . $e->getMessage();
            Log::warning('Reddit detail JSON: connection failed', [
                'permalink' => $permalink,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }

        $this->lastHttpStatus      = $response->status();
        $this->lastResponseSnippet = substr($response->body(), 0, 300);

        // 403 = CDN block, 429 = rate limit — both fall through to RSS silently.
        if ($response->status() === 403) {
            $this->lastFailureReason = 'json_403_blocked_by_cdn';
            return null;
        }

        if ($response->status() === 429) {
            $this->lastFailureReason = 'json_429_rate_limited';
            Log::info('Reddit detail JSON: rate-limited (429), falling back to RSS', [
                'permalink' => $permalink,
            ]);
            return null;
        }

        if ($response->failed()) {
            $this->lastFailureReason = 'json_http_' . $response->status();
            return null;
        }

        $json = $response->json();
        $post = $json[0]['data']['children'][0]['data'] ?? null;

        if (empty($post)) {
            $this->lastFailureReason = 'json_empty_post_data';
            return null;
        }

        return $this->normalizeJsonPost($post);
    }

    // -------------------------------------------------------------------------
    // Detail fetch via RSS
    // -------------------------------------------------------------------------

    private function fetchDetailViaRss(string $permalink): ?array
    {
        preg_match('#/comments/([a-z0-9]+)#i', $permalink, $m);

        if (empty($m[1])) {
            $this->lastFailureReason = 'rss_cannot_extract_post_id';
            Log::warning('Reddit detail RSS: cannot extract post ID', ['permalink' => $permalink]);
            return null;
        }

        $rssUrl = 'https://www.reddit.com/comments/' . $m[1] . '/.rss';

        try {
            $response = $this->rssClient()->get($rssUrl);
        } catch (Throwable $e) {
            $this->lastFailureReason = 'rss_connection_error: ' . $e->getMessage();
            Log::warning('Reddit detail RSS: connection error — will retry', [
                'permalink' => $permalink,
                'error'     => $e->getMessage(),
            ]);
            // Timeout / connection reset are transient — throw so the job retries with backoff.
            throw new RuntimeException(
                'Reddit RSS connection error for ' . $permalink . ': ' . $e->getMessage()
            );
        }

        $this->lastHttpStatus      = $response->status();
        $this->lastResponseSnippet = substr($response->body(), 0, 300);

        if ($response->status() === 429) {
            $this->lastFailureReason = 'rss_429_rate_limited';
            throw new RuntimeException(
                'Reddit RSS rate-limited (429) for ' . $permalink
            );
        }

        if ($response->status() === 404) {
            $this->lastFailureReason = 'rss_404_post_not_found';
            Log::info('Reddit detail RSS: post not found (404)', ['permalink' => $permalink]);
            return null;
        }

        if ($response->failed()) {
            $this->lastFailureReason = 'rss_http_' . $response->status();
            Log::warning('Reddit detail RSS: bad HTTP response', [
                'permalink' => $permalink,
                'status'    => $response->status(),
                'body'      => $this->lastResponseSnippet,
            ]);
            return null;
        }

        return $this->parseDetailRss($response->body(), $permalink);
    }

    private function parseDetailRss(string $body, string $permalink): ?array
    {
        $xml = @simplexml_load_string(preg_replace('/xmlns="[^"]*"/', '', $body));

        if (! $xml || empty($xml->entry)) {
            $this->lastFailureReason = $xml ? 'rss_feed_empty_no_entries' : 'rss_xml_parse_failed';
            return null;
        }

        $entry = $xml->entry[0];
        $link  = $this->extractEntryLink($entry);

        // Author: RSS provides "/u/username" — strip the "/u/" prefix
        $authorRaw = (string) ($entry->author->name ?? '');
        $author    = $authorRaw ? preg_replace('#^/u/#', '', $authorRaw) : null;

        preg_match('/(t3_[a-z0-9]+)/i', (string) ($entry->id ?? ''), $idMatch);
        $externalId = isset($idMatch[1]) ? str_replace('t3_', '', $idMatch[1]) : null;

        // Use <published> for creation time; fall back to <updated>
        $publishedRaw = (string) ($entry->published ?? $entry->updated ?? '');

        // Clean selftext same way as parseRssPage: decode entities → strip tags → remove footer
        $rawSelftext = html_entity_decode(
            (string) ($entry->content ?? $entry->summary ?? ''),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $rawSelftext = strip_tags($rawSelftext);
        $selftext    = trim(preg_replace('/\s*submitted by\s.+$/su', '', $rawSelftext)) ?: null;

        return [
            'reddit_post_id'        => $externalId,
            'permalink'             => $permalink,
            'title'                 => Str::limit((string) ($entry->title ?? 'Untitled'), 255, ''),
            'selftext'              => $selftext,
            'url'                   => $link ?: ('https://www.reddit.com' . $permalink),
            'author'                => $author ?: null,
            'ups'                   => 0,
            'downs'                 => 0,
            'total_awards_received' => 0,
            'created_utc'           => $publishedRaw ? strtotime($publishedRaw) : now()->timestamp,
        ];
    }

    private function normalizeJsonPost(array $post): array
    {
        $selftext = $post['selftext'] ?? '';
        if (in_array($selftext, ['[deleted]', '[removed]'], true)) {
            $selftext = null;
        }

        return [
            'reddit_post_id'        => $post['id'] ?? null,
            'permalink'             => isset($post['permalink'])
                ? rtrim($post['permalink'], '/') . '/'
                : '',
            'title'                 => Str::limit($post['title'] ?? 'Untitled', 255, ''),
            'selftext'              => ($selftext ?: null),
            'url'                   => $post['url'] ?? '',
            'author'                => $post['author'] ?? null,
            'ups'                   => (int) ($post['ups'] ?? 0),
            'downs'                 => (int) ($post['downs'] ?? 0),
            'total_awards_received' => (int) ($post['total_awards_received'] ?? 0),
            'created_utc'           => (int) ($post['created_utc'] ?? now()->timestamp),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function extractEntryLink($entry): string
    {
        foreach ($entry->link as $l) {
            $href = (string) ($l->attributes()['href'] ?? '');
            if ($href) {
                return $href;
            }
        }
        return '';
    }

    private function urlToPermalink(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return $path ? rtrim($path, '/') . '/' : null;
    }

    /**
     * HTTP client for JSON API calls.
     *
     * Search: timeout(15) — search.json either 403s immediately or responds fast.
     * Detail: timeout(5)  — Reddit CDN stalls at 955 bytes then drops the connection;
     *                        fail fast so the RSS fallback runs without wasting 15s.
     */
    private function jsonClient(int $timeout = 5): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(4)
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/json, text/plain, */*',
            ]);
    }

    /**
     * HTTP client for RSS feed calls.
     * RSS is the reliable path — give it enough time to respond.
     */
    private function rssClient(int $timeout = 25): PendingRequest
    {
        return Http::timeout($timeout)
            ->connectTimeout(5)
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/rss+xml, application/xml, text/xml, */*',
            ]);
    }
}
