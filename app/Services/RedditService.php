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
                    'raw_data'    => $post,
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

            // Extract selftext from RSS content field.
            // Strip HTML tags and remove Reddit's "submitted by /u/... [link] [comments]" footer.
            $rawContent = strip_tags((string) ($entry->content ?? $entry->summary ?? ''));
            $selftext   = trim(preg_replace('/\s*submitted by\s.+$/su', '', $rawContent));
            $selftext   = html_entity_decode($selftext, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $entries[] = [
                'source'      => 'reddit',
                'external_id' => $externalId,
                'permalink'   => $permalink,
                'title'       => Str::limit((string) ($entry->title ?? 'Untitled'), 255, ''),
                'url'         => $link ?: '#',
                'selftext'    => $selftext ?: null,
                'raw_data'    => [],
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

        $rawResponse = $response->json();
        $post        = $rawResponse[0]['data']['children'][0]['data'] ?? null;

        if (empty($post)) {
            $this->lastFailureReason = 'json_empty_post_data';
            return null;
        }

        return $this->normalizeJsonPost($post, $rawResponse);
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

        return [
            'reddit_post_id'        => $externalId,
            'permalink'             => $permalink,
            'title'                 => Str::limit((string) ($entry->title ?? 'Untitled'), 255, ''),
            'selftext'              => strip_tags((string) ($entry->content ?? $entry->summary ?? '')) ?: null,
            'url'                   => $link ?: ('https://www.reddit.com' . $permalink),
            'author'                => $author ?: null,
            'ups'                   => 0,
            'downs'                 => 0,
            'total_awards_received' => 0,
            'created_utc'           => $publishedRaw ? strtotime($publishedRaw) : now()->timestamp,
            'raw_json'              => null,
        ];
    }

    private function normalizeJsonPost(array $post, array $rawResponse): array
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
            'raw_json'              => $rawResponse,
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
