<?php

namespace App\Http\Controllers;

use App\Models\RedditPost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class RedditPostController extends Controller
{
    public function index(): View
    {
        $posts = RedditPost::latest()->paginate(20);

        return view('reddit-posts.index', compact('posts'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'reddit_url' => ['required', 'url', 'max:500'],
        ]);

        $inputUrl = trim($request->input('reddit_url'));

        // Must be a Reddit post URL with /comments/ in the path
        if (! preg_match('#reddit\.com/r/[^/]+/comments/[^/]+#i', $inputUrl)) {
            return back()
                ->withErrors(['reddit_url' => 'Please enter a valid Reddit post URL (e.g. https://www.reddit.com/r/SEO/comments/xxxxx/title/).'])
                ->withInput();
        }

        // Strip query string / fragment, remove trailing slash
        $cleanUrl  = rtrim(preg_replace('/[?#].*/', '', $inputUrl), '/');
        $jsonUrl   = $cleanUrl . '/.json';
        $permalink = rtrim(parse_url($cleanUrl, PHP_URL_PATH), '/') . '/';

        // Prevent duplicate storage
        if (RedditPost::where('permalink', $permalink)->exists()) {
            return back()
                ->with('info', 'This Reddit post is already saved in the database.')
                ->withInput();
        }

        // Try JSON first (works without OAuth on some servers)
        $post = $this->fetchViaJson($jsonUrl);

        // Fall back to RSS if JSON is blocked
        if (! $post) {
            $post = $this->fetchViaRss($cleanUrl, $permalink);
        }

        if (! $post) {
            return back()
                ->withErrors(['reddit_url' => 'Could not fetch post data. Reddit may be blocking server-side requests for this post. Try a different URL or check that the post is public.'])
                ->withInput();
        }

        if (isset($post['error'])) {
            return back()
                ->withErrors(['reddit_url' => $post['error']])
                ->withInput();
        }

        // Store the post
        RedditPost::create([
            'permalink'             => $post['permalink'],
            'title'                 => $post['title'],
            'selftext'              => $post['selftext'] ?? null,
            'url'                   => $post['url'],
            'author'                => $post['author'] ?? null,
            'ups'                   => (int) ($post['ups'] ?? 0),
            'downs'                 => (int) ($post['downs'] ?? 0),
            'total_awards_received' => (int) ($post['total_awards_received'] ?? 0),
            'created_utc'           => (int) ($post['created_utc'] ?? now()->timestamp),
        ]);

        $source = $post['_source'] ?? 'json';

        return redirect()
            ->route('reddit-posts.index')
            ->with('success', 'Reddit post saved successfully!' . ($source === 'rss' ? ' (fetched via RSS — ups/downs/awards unavailable without OAuth)' : ''));
    }

    public function destroy(RedditPost $redditPost): RedirectResponse
    {
        $redditPost->delete();

        return back()->with('success', 'Reddit post deleted.');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch Reddit post data using the public .json endpoint.
     * Returns null when Reddit blocks the request (403).
     *
     * @return array<string,mixed>|null
     */
    private function fetchViaJson(string $jsonUrl): ?array
    {
        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept'          => 'application/json',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->get($jsonUrl);
        } catch (Throwable $e) {
            Log::warning('Reddit JSON fetch failed.', ['url' => $jsonUrl, 'error' => $e->getMessage()]);
            return null;
        }

        // 403 = Reddit is blocking server-side access — silently fall through to RSS
        if ($response->status() === 403) {
            return null;
        }

        if ($response->status() === 429) {
            return ['error' => 'Reddit rate limit reached. Please wait a moment and try again.'];
        }

        if ($response->status() === 404) {
            return ['error' => 'Reddit post not found (404). Please check the URL.'];
        }

        if ($response->failed()) {
            return ['error' => 'Reddit returned an error (HTTP ' . $response->status() . '). Please try again.'];
        }

        $data     = $response->json();
        $children = $data[0]['data']['children'] ?? [];

        if (empty($children)) {
            return ['error' => 'No post data found. The post may have been deleted or set to private.'];
        }

        $post = $children[0]['data'] ?? [];

        if (empty($post)) {
            return ['error' => 'Could not extract post data from Reddit response.'];
        }

        // Detect deleted / removed posts
        $selftext = $post['selftext'] ?? '';
        if (in_array($selftext, ['[deleted]', '[removed]'], true) && ! empty($post['removed_by_category'])) {
            return ['error' => 'This Reddit post has been deleted or removed by moderators.'];
        }

        return [
            '_source'               => 'json',
            'permalink'             => rtrim(parse_url(rtrim($jsonUrl, '/.json'), PHP_URL_PATH), '/') . '/',
            'title'                 => $post['title'] ?? 'Untitled',
            'selftext'              => $selftext ?: null,
            'url'                   => $post['url'] ?? '',
            'author'                => $post['author'] ?? null,
            'ups'                   => (int) ($post['ups'] ?? 0),
            'downs'                 => (int) ($post['downs'] ?? 0),
            'total_awards_received' => (int) ($post['total_awards_received'] ?? 0),
            'created_utc'           => (int) ($post['created_utc'] ?? now()->timestamp),
        ];
    }

    /**
     * Fallback: fetch Reddit post data from the Atom RSS feed.
     * Uses https://www.reddit.com/comments/POST_ID/.rss — no auth required.
     * ups / downs / total_awards_received are not available in RSS.
     *
     * @return array<string,mixed>|null
     */
    private function fetchViaRss(string $cleanUrl, string $permalink): ?array
    {
        // Extract post ID from URL: /r/sub/comments/POST_ID/slug/
        if (! preg_match('#/comments/([a-z0-9]+)#i', $cleanUrl, $m)) {
            return null;
        }

        $postId = $m[1];
        $rssUrl = 'https://www.reddit.com/comments/' . $postId . '/.rss';

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept'     => 'application/rss+xml, application/xml, text/xml, */*',
                ])
                ->get($rssUrl);
        } catch (Throwable $e) {
            Log::warning('Reddit RSS fetch failed.', ['url' => $rssUrl, 'error' => $e->getMessage()]);
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $xml = @simplexml_load_string(
            preg_replace('/xmlns="[^"]*"/', '', $response->body())
        );

        if (! $xml || empty($xml->entry)) {
            return null;
        }

        // First entry is the post itself
        $entry = $xml->entry[0];

        // Extract link href
        $link = '';
        foreach ($entry->link as $l) {
            $href = (string) ($l->attributes()['href'] ?? '');
            if ($href) {
                $link = $href;
                break;
            }
        }

        // Derive permalink from the actual link in the feed (more accurate than input URL)
        $resolvedPermalink = $link
            ? rtrim(parse_url($link, PHP_URL_PATH), '/') . '/'
            : $permalink;

        // Author: RSS provides "/u/username" — strip the "/u/" prefix
        $authorRaw = (string) ($entry->author->name ?? '');
        $author    = $authorRaw ? preg_replace('#^/u/#', '', $authorRaw) : null;

        // Use <published> for creation time; fall back to <updated>
        $publishedRaw = (string) ($entry->published ?? $entry->updated ?? '');
        $createdUtc   = $publishedRaw ? strtotime($publishedRaw) : now()->timestamp;

        $selftext = strip_tags((string) ($entry->content ?? $entry->summary ?? ''));

        return [
            '_source'               => 'rss',
            'permalink'             => $resolvedPermalink,
            'title'                 => (string) ($entry->title ?? 'Untitled Reddit post'),
            'selftext'              => $selftext ?: null,
            'url'                   => $link ?: $cleanUrl,
            'author'                => $author ?: null,
            'ups'                   => 0,
            'downs'                 => 0,
            'total_awards_received' => 0,
            'created_utc'           => (int) $createdUtc,
        ];
    }
}
