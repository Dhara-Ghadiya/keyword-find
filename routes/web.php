<?php

use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SearchController::class, 'index'])->name('home');
Route::post('/searches', [SearchController::class, 'store'])->name('searches.store');
Route::get('/searches/{search}', [SearchController::class, 'show'])->name('searches.show');
