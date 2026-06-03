<?php

namespace App\Http\Controllers;

use App\Jobs\FetchKeywordResultsJob;
use App\Models\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    /** Homepage — search form only. */
    public function index(): View
    {
        return view('home');
    }

    /** Validate keyword, create search record, dispatch job, redirect to results page. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'keyword' => ['required', 'string', 'min:2', 'max:191'],
        ]);

        $search = Search::create([
            'keyword' => $validated['keyword'],
            'status'  => 'queued',
        ]);

        FetchKeywordResultsJob::dispatch($search->id);

        return redirect()->route('searches.show', $search);
    }

    /** Results page for a specific search. */
    public function show(Search $search): View
    {
        $search->loadCount('results');

        if ($search->results_count > 0) {
            $search->load(['results' => function ($query) {
                $query->orderBy('source')->limit(25);
            }]);
        }

        return view('searches.show', compact('search'));
    }
}
