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
                            autofocus
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
            </div>
        </div>
    </section>

    <script>
        const form   = document.getElementById('keyword-search-form');
        const input  = document.getElementById('keyword');
        const button = document.getElementById('search-button');

        form.addEventListener('submit', () => {
            if (! input.value.trim()) return;
            button.disabled = true;
            document.getElementById('button-text').textContent = 'Searching';
            document.getElementById('button-loader').classList.remove('hidden');
        });
    </script>
@endsection
