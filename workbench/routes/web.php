<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Http\Controllers\PostSummaryController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');
Route::get('/posts/{post}/summary', [PostSummaryController::class, 'show'])->name('posts.summary');
