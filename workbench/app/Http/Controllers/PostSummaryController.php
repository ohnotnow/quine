<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Workbench\App\Http\Resources\PostResource;
use Workbench\App\Models\Post;
use Workbench\App\Support\PostCache;
use Workbench\App\Support\PostSummary;

class PostSummaryController
{
    public function show(Post $post): string
    {
        return (new PostSummary)->line($post);
    }

    public function index(): AnonymousResourceCollection
    {
        return PostResource::collection(Post::all());
    }

    public function cached(Post $post): string
    {
        return (new PostCache)->title($post);
    }
}
