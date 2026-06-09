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
     * Validate keyword, find or create a Search record, dispatch the
     * next Reddit fetch batch, and redirect to the results page.
     *
     * Pagination contract:
     *   Each submission fetches the next 25 Reddit posts.
     *   Pagination ends when reddit_after = null AND reddit_sync_status
     *   is 'completed' or 'no_results'.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'keyword' => ['required', 'string', 'min:2', 'max:191'],
        ]);

        $keyword = trim($request->input('keyword'));
        $search  = Search::where('keyword', $keyword)->latest()->first();

        // All Reddit posts fetched — nothing more to do
        if ($search && $search->isFullyDone()) {
            return redirect()
                ->route('searches.show', $search)
                ->with('info', 'All available Reddit posts for "' . $keyword . '" have been fetched ('
                    . $search->total_fetched . ' total). Reddit has no more results.');
        }

        // Block duplicate dispatch while a batch is in flight
        if ($search && in_array($search->reddit_sync_status, ['queued', 'running'], true)) {
            return redirect()
                ->route('searches.show', $search)
                ->with('warning', 'This keyword is already being fetched. Please wait.');
        }

        // First search — create record
        if (! $search) {
            $search = Search::create([
                'keyword'            => $keyword,
                'reddit_sync_status' => 'queued',
                'reddit_after'       => null,
                'total_fetched'      => 0,
            ]);
        } else {
            // Subsequent search — queue next batch
            $search->update(['reddit_sync_status' => 'queued']);
        }

        FetchKeywordResultsJob::dispatch($search->id);

        return redirect()->route('searches.show', $search);
    }

    /**
     * Results page — GET only, never dispatches any jobs.
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
