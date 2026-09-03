<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Workbench\App\Models\Post;

class PostController
{
    public function show(Post $post): View
    {
        return view('posts.show', ['post' => $post]);
    }
}
