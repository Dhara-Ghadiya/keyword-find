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

                <a
                    href="/"
                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-medium text-slate-600 shadow-sm transition hover:border-teal-300 hover:text-teal-700"
                >
                    ← New search
                </a>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-8">

                {{-- Keyword + status bar --}}
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Results for</p>
                        <h1 class="mt-1 text-2xl font-bold text-slate-950 sm:text-3xl">
                            {{ $search->keyword }}
                        </h1>
                    </div>

                    <div class="flex items-center gap-3">
                        {{-- Result count --}}
                        <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm font-medium text-slate-700">
                            {{ $search->results_count }} {{ Str::plural('result', $search->results_count) }}
                        </span>

                        {{-- Status badge --}}
                        @php
                            $statusColor = match($search->status) {
                                'completed'  => 'bg-teal-50 text-teal-700 border-teal-200',
                                'running'    => 'bg-blue-50 text-blue-700 border-blue-200',
                                'queued'     => 'bg-amber-50 text-amber-700 border-amber-200',
                                'no_results' => 'bg-slate-50 text-slate-600 border-slate-200',
                                'failed'     => 'bg-rose-50 text-rose-700 border-rose-200',
                                default      => 'bg-slate-50 text-slate-600 border-slate-200',
                            };
                        @endphp
                        <span class="rounded-full border px-3 py-1 text-sm font-semibold capitalize {{ $statusColor }}">
                            @if (in_array($search->status, ['queued', 'running']))
                                <span class="mr-1.5 inline-block size-2 animate-pulse rounded-full bg-current"></span>
                            @endif
                            {{ str_replace('_', ' ', $search->status) }}
                        </span>
                    </div>
                </div>

                {{-- Processing message --}}
                @if (in_array($search->status, ['queued', 'running']))
                    <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                        Fetching results — this page refreshes automatically every 3 seconds.
                    </div>
                @endif

                {{-- No results --}}
                @if ($search->status === 'no_results')
                    <div class="mt-8 rounded-lg border border-slate-200 bg-slate-50 px-4 py-10 text-center">
                        <p class="text-base font-semibold text-slate-900">No results found</p>
                        <p class="mt-2 text-sm text-slate-500">
                            Try a broader keyword or a different topic.
                        </p>
                        <a href="/" class="mt-4 inline-flex items-center rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700">
                            Try another search
                        </a>
                    </div>
                @endif

                {{-- Failed --}}
                @if ($search->status === 'failed')
                    <div class="mt-8 rounded-lg border border-rose-200 bg-rose-50 px-4 py-6 text-center">
                        <p class="text-base font-semibold text-rose-800">Search failed</p>
                        <p class="mt-2 text-sm text-rose-600">
                            An error occurred while fetching results. Please try again.
                        </p>
                        <a href="/" class="mt-4 inline-flex items-center rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700">
                            Try again
                        </a>
                    </div>
                @endif

                {{-- Results list --}}
                @if ($search->results_count > 0)
                    <div class="mt-8 overflow-hidden rounded-lg border border-slate-200">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                <h2 class="text-sm font-semibold text-slate-900">Posts</h2>
                                <p class="text-xs font-medium text-slate-500">
                                    Showing {{ min($search->results_count, 25) }} of {{ $search->results_count }}
                                </p>
                            </div>
                        </div>

                        <div class="divide-y divide-slate-200 bg-white">
                            @foreach ($search->results as $result)
                                <a
                                    href="{{ $result->url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="block px-4 py-4 transition hover:bg-slate-50"
                                >
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-slate-950">
                                                {{ $result->title }}
                                            </p>
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
                @endif

            </div>
        </div>
    </section>

    <script>
        @if (in_array($search->status, ['queued', 'running']))
            setTimeout(() => window.location.reload(), 3000);
        @endif
    </script>
@endsection
