<?php

namespace App\Http\Controllers;

use App\Jobs\FetchKeywordResultsJob;
use App\Models\Search;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class SearchController extends Controller
{
    public function index(): View
    {
        $latestSearch = Search::with(['results' => function ($query) {
            $query->orderBy('source')->orderBy('position')->limit(100);
        }])->withCount('results')->latest()->first();

        $recentSearches = Search::withCount('results')
            ->latest()
            ->take(5)
            ->get();

        return view('home', [
            'latestSearch' => $latestSearch,
            'recentSearches' => $recentSearches,
            'googleConfigured' => filled(config('services.google.search_api_key'))
                && filled(config('services.google.search_engine_id')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'keyword' => ['required', 'string', 'min:2', 'max:191'],
        ]);

        $search = Search::create([
            'keyword' => $validated['keyword'],
            'status' => 'queued',
        ]);

        FetchKeywordResultsJob::dispatch($search->id);

        return redirect()
            ->route('home')
            ->with('status', 'Keyword queued. Results will appear automatically in a few seconds.');
    }
}
