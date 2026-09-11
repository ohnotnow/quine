<?php

namespace Workbench\App\Console\Commands;

use Illuminate\Console\Command;
use Workbench\App\Models\Post;

/**
 * A scheduled command that reaches a mailable through a model: the
 * "rarely fired mailable sent from an artisan command" chain.
 */
class AnnouncePosts extends Command
{
    protected $signature = 'posts:announce';

    protected $description = 'Announce every post';

    public function handle(): void
    {
        Post::all()->each(fn (Post $post) => $post->announcement());
    }
}
