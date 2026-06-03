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

                <a href="/" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-medium text-slate-600 shadow-sm transition hover:border-teal-300 hover:text-teal-700">
                    + New search
                </a>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-8">

                <h1 class="text-2xl font-bold text-slate-950">Past Searches</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Click a keyword to view its results or fetch the next batch of posts.
                </p>

                @if ($searches->isEmpty())
                    <div class="mt-10 rounded-lg border border-slate-200 bg-slate-50 px-4 py-10 text-center">
                        <p class="text-base font-semibold text-slate-800">No searches yet</p>
                        <p class="mt-1 text-sm text-slate-500">Use the search form to fetch your first batch of Reddit posts.</p>
                        <a href="/" class="mt-4 inline-flex items-center rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-teal-700">
                            Start searching
                        </a>
                    </div>
                @else
                    <div class="mt-6 overflow-hidden rounded-lg border border-slate-200">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Keyword</th>
                                    <th class="px-4 py-3 text-left">Status</th>
                                    <th class="px-4 py-3 text-right">Posts fetched</th>
                                    <th class="px-4 py-3 text-right">Searched</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white">
                                @foreach ($searches as $search)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-4 py-3 font-semibold text-slate-950">
                                            <a href="{{ route('searches.show', $search) }}" class="hover:text-teal-700 hover:underline">
                                                {{ $search->keyword }}
                                            </a>
                                        </td>

                                        <td class="px-4 py-3">
                                            @if ($search->is_fully_synced)
                                                <span class="inline-flex items-center rounded-full border border-teal-200 bg-teal-50 px-2.5 py-0.5 text-xs font-semibold text-teal-700">
                                                    Fully synced
                                                </span>
                                            @elseif ($search->total_fetched > 0)
                                                <span class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700">
                                                    More available
                                                </span>
                                            @elseif (in_array($search->status, ['queued', 'running']))
                                                <span class="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700">
                                                    <span class="size-1.5 animate-pulse rounded-full bg-amber-500"></span>
                                                    {{ ucfirst($search->status) }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                                                    {{ ucfirst(str_replace('_', ' ', $search->status)) }}
                                                </span>
                                            @endif
                                        </td>

                                        <td class="px-4 py-3 text-right text-slate-700">
                                            {{ $search->total_fetched }}
                                        </td>

                                        <td class="px-4 py-3 text-right text-slate-400">
                                            {{ $search->created_at->diffForHumans() }}
                                        </td>

                                        <td class="px-4 py-3 text-right">
                                            @if ($search->is_fully_synced)
                                                <span class="text-xs text-slate-400">All fetched</span>
                                            @else
                                                <form action="{{ route('searches.store') }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="keyword" value="{{ $search->keyword }}">
                                                    <button
                                                        type="submit"
                                                        class="rounded-md bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500"
                                                    >
                                                        {{ $search->total_fetched > 0 ? 'Fetch next 25' : 'Fetch' }}
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($searches->hasPages())
                        <div class="mt-6">
                            {{ $searches->links() }}
                        </div>
                    @endif
                @endif

            </div>
        </div>
    </section>
@endsection
