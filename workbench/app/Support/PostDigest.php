<?php

namespace Workbench\App\Support;

use Workbench\App\Models\Post;

/**
 * Nothing constructs or calls this: the walk reaches it and finds nothing beyond.
 */
class PostDigest
{
    public function digest(Post $post): string
    {
        return $post->isPublished() ? 'published' : 'draft';
    }
}
