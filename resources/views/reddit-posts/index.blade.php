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
                <span class="hidden rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-medium text-slate-600 shadow-sm sm:inline-flex">
                    Reddit Posts
                </span>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-8">

                {{-- Title --}}
                <div class="mx-auto max-w-2xl text-center">
                    <p class="text-sm font-semibold uppercase tracking-wide text-teal-700">Reddit Intelligence</p>
                    <h1 class="mt-3 text-3xl font-bold text-slate-950 sm:text-4xl">Fetch Reddit Post Data</h1>
                    <p class="mt-4 text-base leading-7 text-slate-600">
                        Paste a Reddit post URL to extract and store its data into the database.
                    </p>
                </div>

                {{-- Form --}}
                <form action="{{ route('reddit-posts.store') }}" method="POST" class="mx-auto mt-8 max-w-2xl">
                    @csrf

                    <div class="flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-2 shadow-inner sm:flex-row">
                        <label for="reddit_url" class="sr-only">Reddit Post URL</label>
                        <input
                            id="reddit_url"
                            name="reddit_url"
                            type="url"
                            value="{{ old('reddit_url') }}"
                            placeholder="https://www.reddit.com/r/SEO/comments/xxxxx/post-name/"
                            class="min-h-12 flex-1 rounded-md border bg-white px-4 text-sm text-slate-950 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:ring-4 focus:ring-teal-100 {{ $errors->has('reddit_url') ? 'border-rose-300' : 'border-transparent' }}"
                            autocomplete="off"
                        >
                        <button
                            type="submit"
                            class="inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-teal-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 focus:outline-none focus:ring-4 focus:ring-teal-200 sm:min-w-28"
                        >
                            Fetch &amp; Save
                        </button>
                    </div>

                    @error('reddit_url')
                        <p class="mt-2 text-sm font-medium text-rose-600">{{ $message }}</p>
                    @enderror
                </form>

                {{-- Alerts --}}
                @if (session('success'))
                    <div class="mx-auto mt-5 max-w-2xl rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-medium text-teal-800">
                        {{ session('success') }}
                    </div>
                @endif

                @if (session('info'))
                    <div class="mx-auto mt-5 max-w-2xl rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                        {{ session('info') }}
                    </div>
                @endif

                {{-- Stats --}}
                <div class="mt-8 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-medium text-slate-500">Total posts saved</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">{{ $posts->total() }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-medium text-slate-500">Total upvotes</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">{{ number_format($posts->sum('ups')) }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-medium text-slate-500">Total awards</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">{{ number_format($posts->sum('total_awards_received')) }}</p>
                    </div>
                </div>

                {{-- Posts Table --}}
                @if ($posts->isNotEmpty())
                    <div class="mt-8 overflow-hidden rounded-lg border border-slate-200">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-slate-900">Saved Reddit Posts</h2>
                            <p class="text-xs text-slate-500">{{ $posts->total() }} total</p>
                        </div>

                        <div class="divide-y divide-slate-200 bg-white">
                            @foreach ($posts as $post)
                                <div class="px-4 py-4">
                                    <div class="flex items-start gap-3">

                                        {{-- Reddit icon --}}
                                        <div class="grid size-10 shrink-0 place-items-center rounded-md bg-orange-50 border border-orange-100">
                                            <svg class="size-5 text-orange-400" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0zm5.01 4.744c.688 0 1.25.561 1.25 1.249a1.25 1.25 0 0 1-2.498.056l-2.597-.547-.8 3.747c1.824.07 3.48.632 4.674 1.488.308-.309.73-.491 1.207-.491.968 0 1.754.786 1.754 1.754 0 .716-.435 1.333-1.01 1.614a3.111 3.111 0 0 1 .042.52c0 2.694-3.13 4.87-7.004 4.87-3.874 0-7.004-2.176-7.004-4.87 0-.183.015-.366.043-.534A1.748 1.748 0 0 1 4.028 12c0-.968.786-1.754 1.754-1.754.463 0 .898.196 1.207.49 1.207-.883 2.878-1.43 4.744-1.487l.885-4.182a.342.342 0 0 1 .14-.197.35.35 0 0 1 .238-.042l2.906.617a1.214 1.214 0 0 1 1.108-.701zM9.25 12C8.561 12 8 12.562 8 13.25c0 .687.561 1.248 1.25 1.248.687 0 1.248-.561 1.248-1.249 0-.688-.561-1.249-1.249-1.249zm5.5 0c-.687 0-1.248.561-1.248 1.25 0 .687.561 1.248 1.249 1.248.688 0 1.249-.561 1.249-1.249 0-.687-.562-1.249-1.25-1.249zm-5.466 3.99a.327.327 0 0 0-.231.094.33.33 0 0 0 0 .463c.842.842 2.484.913 2.961.913.477 0 2.105-.056 2.961-.913a.361.361 0 0 0 .029-.463.33.33 0 0 0-.464 0c-.547.533-1.684.73-2.512.73-.828 0-1.979-.196-2.512-.73a.326.326 0 0 0-.232-.095z"/>
                                            </svg>
                                        </div>

                                        {{-- Content --}}
                                        <div class="min-w-0 flex-1">
                                            <a
                                                href="{{ $post->reddit_url }}"
                                                target="_blank"
                                                class="text-sm font-semibold text-slate-950 hover:text-teal-700 hover:underline"
                                            >
                                                {{ $post->title }}
                                            </a>

                                            @if ($post->selftext)
                                                <p class="mt-1 line-clamp-2 text-xs text-slate-500">{{ $post->selftext }}</p>
                                            @endif

                                            {{-- Meta --}}
                                            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                                <span class="inline-flex items-center gap-1">
                                                    <svg class="size-3.5 text-orange-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 4l2.5 5 5.5.8-4 3.9.9 5.3L12 16.5l-4.9 2.5.9-5.3-4-3.9 5.5-.8z"/></svg>
                                                    {{ number_format($post->ups) }} upvotes
                                                </span>
                                                <span>{{ number_format($post->downs) }} downvotes</span>
                                                @if ($post->total_awards_received)
                                                    <span>🏆 {{ $post->total_awards_received }} awards</span>
                                                @endif
                                                <span>{{ $post->posted_at }}</span>
                                                <a href="{{ $post->url }}" target="_blank" class="text-teal-600 hover:underline truncate max-w-xs">
                                                    {{ parse_url($post->url, PHP_URL_HOST) }}
                                                </a>
                                            </div>
                                        </div>

                                        {{-- Delete --}}
                                        <form action="{{ route('reddit-posts.destroy', $post) }}" method="POST" class="shrink-0">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                onclick="return confirm('Delete this post?')"
                                                class="rounded-md border border-slate-200 p-1.5 text-slate-400 transition hover:border-rose-200 hover:text-rose-500"
                                                title="Delete"
                                            >
                                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </form>

                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Pagination --}}
                        @if ($posts->hasPages())
                            <div class="border-t border-slate-200 bg-slate-50 px-4 py-3">
                                {{ $posts->links() }}
                            </div>
                        @endif
                    </div>
                @else
                    <div class="mt-8 rounded-lg border border-slate-200 bg-slate-50 px-4 py-8 text-center">
                        <p class="text-sm font-semibold text-slate-900">No posts saved yet</p>
                        <p class="mt-1 text-sm text-slate-500">Paste a Reddit post URL above to get started.</p>
                    </div>
                @endif

            </div>
        </div>
    </section>
@endsection
