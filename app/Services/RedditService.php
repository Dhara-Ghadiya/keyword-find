<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * Fetch detailed Reddit post data for a given permalink.
     * Tries public .json first; falls back to public RSS.
     */
    public function fetchPostDetail(string $permalink): ?array
    {
        return $this->fetchDetailViaJson($permalink)
            ?? $this->fetchDetailViaRss($permalink);
    }

    // -------------------------------------------------------------------------
    // Search via JSON API
    // -------------------------------------------------------------------------

    private function searchViaJson(string $keyword, int $limit): array
    {
        try {
            $response = $this->jsonClient()
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

                return [
                    'source'      => 'reddit',
                    'external_id' => $post['id'] ?? null,
                    'permalink'   => isset($post['permalink'])
                        ? rtrim($post['permalink'], '/') . '/'
                        : null,
                    'title'       => Str::limit($post['title'] ?? 'Untitled', 255, ''),
                    'url'         => $post['url'] ?? '#',
                    'snippet'     => Str::limit($post['selftext'] ?? '', 500, ''),
                    'position'    => $index + 1,
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
                break;  // no more results
            }

            $all   = array_merge($all, $entries);
            $after = $lastFullname;
            $page++;

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

            $entries[] = [
                'source'      => 'reddit',
                'external_id' => $externalId,
                'permalink'   => $permalink,
                'title'       => Str::limit((string) ($entry->title ?? 'Untitled'), 255, ''),
                'url'         => $link ?: '#',
                'snippet'     => Str::limit(
                    strip_tags((string) ($entry->content ?? $entry->summary ?? '')),
                    500,
                    ''
                ),
                'position'    => count($entries) + 1,  // will be renumbered after merge
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
            Log::warning('Reddit detail JSON: connection failed', [
                'permalink' => $permalink,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }

        // 403 = CDN block — fall through to RSS
        if ($response->status() === 403 || $response->failed()) {
            return null;
        }

        $rawResponse = $response->json();
        $post        = $rawResponse[0]['data']['children'][0]['data'] ?? null;

        if (empty($post)) {
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
            Log::warning('Reddit detail RSS: cannot extract post ID', ['permalink' => $permalink]);
            return null;
        }

        $rssUrl = 'https://www.reddit.com/comments/' . $m[1] . '/.rss';

        try {
            $response = $this->rssClient()->get($rssUrl);
        } catch (Throwable $e) {
            Log::warning('Reddit detail RSS: connection failed', [
                'permalink' => $permalink,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->failed()) {
            Log::warning('Reddit detail RSS: bad HTTP response', [
                'permalink' => $permalink,
                'status'    => $response->status(),
            ]);
            return null;
        }

        return $this->parseDetailRss($response->body(), $permalink);
    }

    private function parseDetailRss(string $body, string $permalink): ?array
    {
        $xml = @simplexml_load_string(preg_replace('/xmlns="[^"]*"/', '', $body));

        if (! $xml || empty($xml->entry)) {
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

    private function jsonClient(): PendingRequest
    {
        return Http::timeout(15)
            ->connectTimeout(5)
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/json, text/plain, */*',
            ]);
    }

    private function rssClient(): PendingRequest
    {
        return Http::timeout(15)
            ->connectTimeout(5)
            ->withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/rss+xml, application/xml, text/xml, */*',
            ]);
    }
}
