<?php

namespace Workbench\App\Http\Controllers;

use Workbench\App\Models\Post;
use Workbench\App\Support\PostSummary;

class PostSummaryController
{
    public function show(Post $post): string
    {
        return (new PostSummary)->line($post);
    }
}
