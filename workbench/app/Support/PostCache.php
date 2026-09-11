<?php

namespace Workbench\App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;

/**
 * Framework sinks whose keys are built from model attributes: the walk needs
 * to know a read feeds a cache key or a storage path.
 */
class PostCache
{
    public function title(Post $post): string
    {
        return Cache::remember(
            "post:{$post->id}:{$post->title}:{$post->title_label}",
            60,
            fn () => $post->title,
        );
    }

    public function export(Comment $comment): bool
    {
        return Storage::put(
            "exports/{$comment->author->id}/{$comment->id}.md",
            $comment->body,
        );
    }
}
