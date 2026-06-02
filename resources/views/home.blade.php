@extends('layouts.app')

@section('content')
    <section class="flex min-h-screen items-center px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto w-full max-w-4xl">
            <div class="mb-8 flex items-center justify-between gap-4">
                <a href="/" class="inline-flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-lg bg-teal-600 text-sm font-bold text-white shadow-sm">
                        KF
                    </span>
                    <span class="text-base font-semibold text-slate-900">Keyword Find</span>
                </a>

                <span class="hidden rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-medium text-slate-600 shadow-sm sm:inline-flex">
                    Research workspace
                </span>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-8 lg:p-10">
                <div class="mx-auto max-w-2xl text-center">
                    <p class="text-sm font-semibold uppercase tracking-wide text-teal-700">Search intelligence</p>

                    <h1 class="mt-3 text-3xl font-bold text-slate-950 sm:text-4xl">
                        Find keyword opportunities faster
                    </h1>

                    <p class="mt-4 text-base leading-7 text-slate-600">
                        Enter a topic, brand, or seed keyword to start collecting ideas for your next content plan.
                    </p>
                </div>

                <form
                    id="keyword-search-form"
                    action="{{ route('searches.store') }}"
                    method="POST"
                    class="mx-auto mt-8 max-w-2xl"
                >
                    @csrf

                    <div class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-2 shadow-inner sm:flex-row">
                        <label for="keyword" class="sr-only">Search keyword</label>

                        <input
                            id="keyword"
                            name="keyword"
                            type="search"
                            value="{{ old('keyword') }}"
                            placeholder="Search keywords, topics, or competitors"
                            class="min-h-12 flex-1 rounded-md border bg-white px-4 text-base text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-100 {{ $errors->has('keyword') ? 'border-rose-300' : 'border-transparent' }}"
                            autocomplete="off"
                        >

                        <button
                            id="search-button"
                            type="submit"
                            class="inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-teal-600 px-5 text-base font-semibold text-white shadow-sm transition hover:bg-teal-700 focus:outline-none focus:ring-4 focus:ring-teal-200 disabled:cursor-wait disabled:bg-teal-500 sm:min-w-32"
                        >
                            <span id="button-text">Search</span>
                            <span
                                id="button-loader"
                                class="hidden size-5 animate-spin rounded-full border-2 border-white/40 border-t-white"
                                aria-hidden="true"
                            ></span>
                        </button>
                    </div>

                    @error('keyword')
                        <p class="mt-2 text-sm font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </form>

                @if (session('status'))
                    <div class="mx-auto mt-6 max-w-2xl rounded-lg border border-teal-100 bg-teal-50 px-4 py-3 text-sm font-medium text-teal-800">
                        {{ session('status') }}
                    </div>
                @endif


                <div class="mt-8 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-medium text-slate-500">Latest keyword</p>
                        <p class="mt-2 truncate text-2xl font-bold text-slate-950">
                            {{ $latestSearch?->keyword ?? '-' }}
                        </p>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-medium text-slate-500">Saved results</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">
                            {{ $latestSearch?->results_count ?? 0 }}
                        </p>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-medium text-slate-500">Status</p>
                        <p class="mt-2 text-2xl font-bold capitalize text-slate-950">
                            {{ $latestSearch?->status ?? 'ready' }}
                        </p>
                    </div>
                </div>

                @if ($latestSearch && $latestSearch->status === 'no_results')
                    <div class="mt-8 rounded-lg border border-slate-200 bg-slate-50 px-4 py-6 text-center">
                        <h2 class="text-base font-semibold text-slate-950">No results found</h2>
                        <p class="mt-2 text-sm text-slate-600">
                            No provider returned results for this keyword. Try a broader keyword or enable Google and Reddit API credentials.
                        </p>
                    </div>
                @endif

                @if ($latestSearch?->results_count)
                    <div class="mt-8 overflow-hidden rounded-lg border border-slate-200">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                <h2 class="text-sm font-semibold text-slate-900">Latest results</h2>
                                <p class="text-xs font-medium text-slate-500">
                                    Showing {{ min($latestSearch->results_count, 25) }} of {{ $latestSearch->results_count }} saved results
                                </p>
                            </div>
                        </div>

                        <div class="divide-y divide-slate-200 bg-white">
                            @foreach ($latestSearch->results as $result)
                                <a href="{{ $result->url }}" target="_blank" class="block px-4 py-4 transition hover:bg-slate-50">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-slate-950">{{ $result->title }}</p>
                                            <p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ $result->selftext }}</p>
                                        </div>

                                        <span class="shrink-0 rounded-full border border-slate-200 px-2 py-1 text-xs font-semibold capitalize text-slate-500">
                                            {{ str_replace('_', ' ', $result->source) }}
                                        </span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($recentSearches->isNotEmpty())
                    <div class="mt-8 overflow-hidden rounded-lg border border-slate-200">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <h2 class="text-sm font-semibold text-slate-900">Recent searches</h2>
                        </div>

                        <div class="divide-y divide-slate-200 bg-white">
                            @foreach ($recentSearches as $search)
                                <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-950">{{ $search->keyword }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $search->created_at->diffForHumans() }}</p>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <span class="rounded-full border border-slate-200 px-2 py-1 text-xs font-semibold capitalize text-slate-600">
                                            {{ str_replace('_', ' ', $search->status) }}
                                        </span>
                                        <span class="text-sm font-semibold text-slate-950">{{ $search->results_count }} results</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    <script>
        const form = document.getElementById('keyword-search-form');
        const input = document.getElementById('keyword');
        const button = document.getElementById('search-button');
        const buttonText = document.getElementById('button-text');
        const buttonLoader = document.getElementById('button-loader');

        form.addEventListener('submit', () => {
            if (!input.value.trim()) {
                return;
            }

            button.disabled = true;
            buttonText.textContent = 'Searching';
            buttonLoader.classList.remove('hidden');
        });

        @if ($latestSearch && in_array($latestSearch->status, ['queued', 'running']))
        setTimeout(() => window.location.reload(), 3000);
        @endif
    </script>
@endsection
