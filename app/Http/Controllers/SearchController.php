<?php

namespace App\Http\Controllers;

use App\Jobs\FetchKeywordResultsJob;
use App\Models\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    /** Homepage — search form + previously searched keywords. */
    public function index(): View
    {
        $searches = Search::withCount('results')
            ->orderByDesc('updated_at')
            ->get();

        return view('home', compact('searches'));
    }

    /**
     * Validate keyword, find or create a Search record, dispatch fetch job,
     * redirect to the results page.
     *
     * Same keyword → continues pagination (fetches next 25 posts).
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'keyword' => ['required', 'string', 'min:2', 'max:191'],
        ]);

        $keyword = trim($request->input('keyword'));

        // Find the most recent search record for this keyword
        $search = Search::where('keyword', $keyword)->latest()->first();

        // All Reddit posts for this keyword have been fetched
        if ($search && $search->is_fully_synced) {
            return redirect()
                ->route('searches.show', $search)
                ->with('info', 'All available Reddit posts for "' . $keyword . '" have been fetched ('
                    . $search->total_fetched . ' total). Reddit has no more results.');
        }

        // Rate-limit: prevent hammering the same keyword within 1 minute
        if ($search
            && $search->last_synced_at
            && $search->last_synced_at->diffInSeconds(now()) < 60
            && in_array($search->status, ['queued', 'running'], true)
        ) {
            return redirect()
                ->route('searches.show', $search)
                ->with('warning', 'This keyword is already being fetched. Please wait.');
        }

        // First search for this keyword → create new record
        if (! $search) {
            $search = Search::create([
                'keyword'         => $keyword,
                'status'          => 'queued',
                'reddit_after'    => null,
                'is_fully_synced' => false,
                'total_fetched'   => 0,
            ]);
        } else {
            // Subsequent search → reuse record, queue next batch
            $search->update(['status' => 'queued']);
        }

        FetchKeywordResultsJob::dispatch($search->id);

        return redirect()->route('searches.show', $search);
    }

    /**
     * Results page for a specific search.
     * Results paginated 25 per page, ordered by batch then insertion order.
     */
    public function show(Search $search): View
    {
        $search->loadCount('results');

        $results = $search->results()
            ->with('redditPost')
            ->orderBy('batch_number')
            ->orderBy('id')
            ->paginate(25);

        return view('searches.show', compact('search', 'results'));
    }
}
