<?php

namespace Workbench\App\Support;

use Workbench\App\Models\Post;

/**
 * A pass-through on the way from the model to a route: the walk goes through it.
 */
class PostSummary
{
    public function line(Post $post): string
    {
        return $post->title.($post->isPublished() ? '' : ' (draft)');
    }
}
