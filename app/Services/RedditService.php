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
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Search Reddit for up to $limit posts matching $keyword.
     * Tries search.json first; falls back to paginated RSS.
     */
    public function search(string $keyword, int $limit = 100): array
    {
        // Attempt 1: public JSON API (has author, ups, downs — may be blocked)
        $results = $this->searchViaJson($keyword, $limit);

        if (! empty($results)) {
            Log::info('Reddit search: used JSON API', ['count' => count($results)]);
            return $results;
        }

        // Attempt 2: paginated RSS — 4 pages × 25 = up to 100 results
        $results = $this->searchViaPaginatedRss($keyword, $limit);

        Log::info('Reddit search: used RSS fallback', ['count' => count($results)]);

        return $results;
    }

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
     */
    private function searchPostsViaJson(string $keyword, ?string $afterToken): ?array
    {
        $params = [
            'q'     => $keyword,
            'limit' => 25,
            'sort'  => 'new',
            't'     => 'all',
            'type'  => 'link',
        ];
        if ($afterToken !== null) {
            $params['after'] = $afterToken;
        }

        try {
            $response = $this->jsonClient(10)->get('https://www.reddit.com/search.json', $params);
        } catch (Throwable $e) {
            Log::warning('Reddit searchPosts JSON: connection failed — trying RSS', [
                'keyword' => $keyword,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }

        // 403 = CDN block, 429 = rate-limited — fall through to RSS silently
        if (in_array($response->status(), [403, 429], true) || $response->failed()) {
            Log::info('Reddit searchPosts JSON: blocked/failed — trying RSS', [
                'keyword' => $keyword,
                'status'  => $response->status(),
            ]);
            return null;
        }

        $data     = $response->json();
        $children = $data['data']['children'] ?? [];
        $newAfter = $data['data']['after'] ?? null;

        $posts = collect($children)->map(function (array $child) {
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
        })->all();

        Log::info('Reddit searchPosts JSON: success', [
            'keyword'   => $keyword,
            'after_in'  => $afterToken,
            'after_out' => $newAfter,
            'count'     => count($posts),
        ]);

        return [
            'posts'       => $posts,
            'after'       => $newAfter,
            'before'      => $data['data']['before'] ?? null,
            'total_found' => count($posts),
        ];
    }

    /**
     * Fetch one page via RSS. Always works. Returns the same normalized shape as the JSON path.
     *
     * `after` token for the next page: the Reddit fullname of the last entry in this page,
     * but only when we received a full page (≥25 raw entries). Fewer = last page.
     *
     * @throws \Exception on connection failures (triggers job retry)
     */
    private function searchPostsViaRss(string $keyword, ?string $afterToken): array
    {
        $params = [
            'q'     => $keyword,
            'limit' => 25,
            'sort'  => 'new',
            't'     => 'all',
        ];
        if ($afterToken !== null) {
            $params['after'] = $afterToken;
        }

        try {
            $response = $this->rssClient()->get('https://www.reddit.com/search.rss', $params);
        } catch (Throwable $e) {
            Log::error('Reddit searchPosts RSS: connection failed', [
                'keyword' => $keyword,
                'error'   => $e->getMessage(),
            ]);
            throw new \Exception('Reddit RSS connection failed: ' . $e->getMessage());
        }

        if ($response->failed()) {
            Log::error('Reddit searchPosts RSS: bad response', [
                'keyword' => $keyword,
                'status'  => $response->status(),
            ]);
            throw new \Exception('Reddit RSS request failed: HTTP ' . $response->status());
        }

        [$rawEntries, $lastFullname] = $this->parseRssPage($response->body());

        // Keep only actual posts — RSS search mixes subreddit home pages with post links
        $posts = array_values(array_filter(
            $rawEntries,
            fn (array $e) => str_contains($e['permalink'] ?? '', '/comments/')
        ));

        // A full page (≥25 raw entries) means there are more results; use last fullname as cursor
        $newAfter = (count($rawEntries) >= 25 && $lastFullname) ? $lastFullname : null;

        $normalizedPosts = collect($posts)->map(fn (array $e) => [
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

        Log::info('Reddit searchPosts RSS: success', [
            'keyword'   => $keyword,
            'after_in'  => $afterToken,
            'after_out' => $newAfter,
            'raw_count' => count($rawEntries),
            'count'     => count($normalizedPosts),
        ]);

        return [
            'posts'       => $normalizedPosts,
            'after'       => $newAfter,
            'before'      => null,
            'total_found' => count($normalizedPosts),
        ];
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

    // -------------------------------------------------------------------------
    // Search via JSON API
    // -------------------------------------------------------------------------

    private function searchViaJson(string $keyword, int $limit): array
    {
        try {
            $response = $this->jsonClient(15)  // search needs more time than detail
                ->get('https://www.reddit.com/search.json', [
                    'q'     => $keyword,
                    'limit' => min($limit, 100),
                    'sort'  => 'relevance',
                    't'     => 'all',
                    'type'  => 'link',
                ]);
        } catch (Throwable $e) {
            Log::warning('Reddit search JSON: connection failed', ['error' => $e->getMessage()]);
            return [];
        }

        // 403 = blocked by Reddit CDN — fall through to RSS
        if ($response->status() === 403 || $response->failed()) {
            return [];
        }

        $children = $response->json('data.children', []);

        if (empty($children)) {
            return [];
        }

        return $this->normalizeJsonSearchResults($children);
    }

    private function normalizeJsonSearchResults(array $children): array
    {
        return collect($children)
            ->values()
            ->map(function (array $child, int $index) {
                $post = $child['data'] ?? [];

                // selftext is the post body. Empty for link posts (posts sharing external URLs).
                $selftext = trim($post['selftext'] ?? '');

                return [
                    'source'      => 'reddit',
                    'external_id' => $post['id'] ?? null,
                    'permalink'   => isset($post['permalink'])
                        ? rtrim($post['permalink'], '/') . '/'
                        : null,
                    'title'       => Str::limit($post['title'] ?? 'Untitled', 255, ''),
                    'url'         => $post['url'] ?? '#',
                    'selftext'    => $selftext ?: null,
                ];
            })
            ->all();
    }

    // -------------------------------------------------------------------------
    // Search via paginated RSS (4 pages × 25 = 100 results)
    // -------------------------------------------------------------------------

    private function searchViaPaginatedRss(string $keyword, int $limit): array
    {
        $all   = [];
        $after = null;   // Reddit fullname token for pagination (e.g. "t3_abc123")
        $page  = 0;
        $perPage = 25;   // Reddit RSS maximum per request

        while (count($all) < $limit) {
            $params = [
                'q'     => $keyword,
                'limit' => $perPage,
                'sort'  => 'relevance',
                't'     => 'all',
            ];

            if ($after) {
                $params['after'] = $after;
            }

            try {
                $response = $this->rssClient()
                    ->get('https://www.reddit.com/search.rss', $params);
            } catch (Throwable $e) {
                Log::warning('Reddit paginated RSS: connection failed', [
                    'page'  => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            if ($response->failed()) {
                Log::warning('Reddit paginated RSS: bad response', [
                    'page'   => $page,
                    'status' => $response->status(),
                ]);
                break;
            }

            [$entries, $lastFullname] = $this->parseRssPage($response->body());

            if (empty($entries)) {
                break;  // no more results from Reddit
            }

            // Filter: keep only actual posts (/comments/ path).
            // Reddit RSS mixes subreddit home pages (e.g. /r/hardware/) with posts.
            // Subreddit pages have no post detail and must be excluded so that
            // results_count == reddit_posts count after processing.
            $posts = array_values(array_filter(
                $entries,
                fn (array $e) => str_contains($e['permalink'] ?? '', '/comments/')
            ));

            $all   = array_merge($all, $posts);
            $after = $lastFullname;
            $page++;

            // Use raw entry count (not filtered) for pagination:
            // if Reddit returned a full page there may be more results to fetch.

            // Stop if Reddit returned fewer than a full page (no more results)
            if (! $after || count($entries) < $perPage) {
                break;
            }
        }

        // Re-number positions sequentially after merging pages
        return collect($all)
            ->take($limit)
            ->values()
            ->map(fn (array $r, int $i) => array_merge($r, ['position' => $i + 1]))
            ->all();
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

            $lastFullname = $fullname;
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
