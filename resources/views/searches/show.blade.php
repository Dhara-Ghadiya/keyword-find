@extends('layouts.app')

@section('content')
    <section class="min-h-screen px-4 py-10 sm:px-6 lg:px-8">
        <div class="mx-auto w-full max-w-4xl">

            {{-- Header --}}
            <div class="mb-8 flex items-center justify-between gap-4">
                <a href="/" class="inline-flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-lg bg-teal-600 text-sm font-bold text-white shadow-sm">
                        KF
                    </span>
                    <span class="text-base font-semibold text-slate-900">Keyword Find</span>
                </a>

                <a href="/" class="rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-medium text-slate-600 shadow-sm transition hover:border-teal-300 hover:text-teal-700">
                    ← New search
                </a>
            </div>

            {{-- Flash messages --}}
            @if (session('info'))
                <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm font-medium text-blue-800">
                    {{ session('info') }}
                </div>
            @endif
            @if (session('warning'))
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                    {{ session('warning') }}
                </div>
            @endif

            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-8">

                @php
                    $isDone         = $search->isFullyDone();
                    $isProcessing   = in_array($search->reddit_sync_status, ['queued', 'running']);
                    $statusColor    = match($search->reddit_sync_status) {
                        'completed'  => 'bg-teal-50 text-teal-700 border-teal-200',
                        'running'    => 'bg-blue-50 text-blue-700 border-blue-200',
                        'queued'     => 'bg-amber-50 text-amber-700 border-amber-200',
                        'no_results' => 'bg-slate-50 text-slate-600 border-slate-200',
                        'failed'     => 'bg-rose-50 text-rose-700 border-rose-200',
                        default      => 'bg-slate-50 text-slate-600 border-slate-200',
                    };
                @endphp

                {{-- Keyword + status bar --}}
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Results for</p>
                        <h1 class="mt-1 text-2xl font-bold text-slate-950 sm:text-3xl">
                            {{ $search->keyword }}
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            @if ($search->total_fetched > 0)
                                {{ $search->total_fetched }} posts fetched across {{ $search->getCurrentBatch() }} {{ Str::plural('batch', $search->getCurrentBatch()) }}
                                @if (! $isDone)
                                    &mdash; more available
                                @endif
                            @else
                                Fetching first batch…
                            @endif
                        </p>
                    </div>

                    <div class="flex flex-col items-start gap-2 sm:items-end">
                        {{-- Status badge --}}
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-semibold {{ $statusColor }}">
                            @if ($isProcessing)
                                <span class="size-2 animate-pulse rounded-full bg-current"></span>
                            @endif
                            {{ ucfirst(str_replace('_', ' ', $search->reddit_sync_status)) }}
                        </span>

                        {{-- Pagination hint --}}
                        @if ($isDone)
                            <span class="text-xs text-slate-400">All Reddit posts fetched</span>
                        @elseif (! $isProcessing && $search->total_fetched > 0)
                            <span class="text-xs text-slate-500">
                                More available — search this keyword again to fetch the next 25
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Processing notice --}}
                @if ($isProcessing)
                    <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                        Fetching batch {{ $search->getNextBatch() }} — page refreshes automatically every 3 seconds.
                    </div>
                @endif

                {{-- Batch range info --}}
                @if ($results->total() > 0)
                    @php
                        $from = (($results->currentPage() - 1) * $results->perPage()) + 1;
                        $to   = min($results->currentPage() * $results->perPage(), $results->total());
                    @endphp
                    <p class="mt-5 text-xs font-medium text-slate-400">
                        Showing {{ $from }}–{{ $to }} of {{ $results->total() }} saved results
                        (Batch 1–{{ $search->getCurrentBatch() }})
                    </p>
                @endif

                {{-- No results --}}
                @if ($search->reddit_sync_status === 'no_results' || ($search->reddit_sync_status === 'completed' && $results->isEmpty()))
                    <div class="mt-8 rounded-lg border border-slate-200 bg-slate-50 px-4 py-10 text-center">
                        <p class="text-base font-semibold text-slate-900">No results found</p>
                        <p class="mt-2 text-sm text-slate-500">Try a broader keyword or a different topic.</p>
                        <a href="/" class="mt-4 inline-flex items-center rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700">
                            Try another search
                        </a>
                    </div>
                @endif

                {{-- Failed --}}
                @if ($search->reddit_sync_status === 'failed')
                    <div class="mt-8 rounded-lg border border-rose-200 bg-rose-50 px-4 py-6 text-center">
                        <p class="text-base font-semibold text-rose-800">Search failed</p>
                        <p class="mt-2 text-sm text-rose-600">An error occurred. Please try again.</p>
                        <form action="{{ route('searches.store') }}" method="POST" class="mt-4 inline-block">
                            @csrf
                            <input type="hidden" name="keyword" value="{{ $search->keyword }}">
                            <button type="submit" class="rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700">
                                Retry
                            </button>
                        </form>
                    </div>
                @endif

                {{-- Results list --}}
                @if ($results->isNotEmpty())
                    <div class="mt-6 overflow-hidden rounded-lg border border-slate-200">
                        <div class="divide-y divide-slate-200 bg-white">
                            @foreach ($results as $result)
                                <a
                                    href="{{ $result->url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="block px-4 py-4 transition hover:bg-slate-50"
                                >
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <p class="truncate text-sm font-semibold text-slate-950">
                                                    {{ $result->title }}
                                                </p>
                                                <span class="shrink-0 rounded-full border border-slate-100 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-400">
                                                    B{{ $result->batch_number }}
                                                </span>
                                            </div>
                                            @if ($result->selftext)
                                                <p class="mt-1 line-clamp-2 text-sm text-slate-500">
                                                    {{ $result->selftext }}
                                                </p>
                                            @endif
                                        </div>

                                        <span class="shrink-0 rounded-full border border-slate-200 px-2 py-1 text-xs font-semibold capitalize text-slate-500">
                                            {{ str_replace('_', ' ', $result->source) }}
                                        </span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    {{-- Pagination links --}}
                    @if ($results->hasPages())
                        <div class="mt-6">
                            {{ $results->links() }}
                        </div>
                    @endif
                @endif

            </div>
        </div>
    </section>

    <script>
        @if ($isProcessing)
            setTimeout(() => window.location.reload(), 3000);
        @endif
    </script>
@endsection
