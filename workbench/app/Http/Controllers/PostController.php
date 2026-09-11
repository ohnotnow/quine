<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Workbench\App\Models\Post;

class PostController
{
    public function show(Post $post): View
    {
        return view('posts.show', ['post' => $post, 'published' => $post->isPublished(), 'title' => $post->title]);
    }

    public function published(): int
    {
        return Post::published()->count() + Post::where('title', '!=', '')->published()->count();
    }

    public function nothing(Post $post): string
    {
        return collect([$post])->map(fn (Post $each) => $each->nothing())->first() ?? '';
    }

    public function teaser(Post $post): string
    {
        return $post->title_label.': '.$post->excerpt;
    }
}
