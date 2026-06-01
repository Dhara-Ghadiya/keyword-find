<?php

use App\Http\Controllers\RedditPostController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SearchController::class, 'index'])->name('home');
Route::post('/searches', [SearchController::class, 'store'])->name('searches.store');

Route::get('/reddit-posts', [RedditPostController::class, 'index'])->name('reddit-posts.index');
Route::post('/reddit-posts', [RedditPostController::class, 'store'])->name('reddit-posts.store');
Route::delete('/reddit-posts/{redditPost}', [RedditPostController::class, 'destroy'])->name('reddit-posts.destroy');
