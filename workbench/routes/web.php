<?php

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\PostController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/posts/{post}', [PostController::class, 'show'])->name('posts.show');
