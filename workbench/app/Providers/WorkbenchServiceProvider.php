<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyEditors;
use Workbench\App\Models\Post;
use Workbench\App\Observers\PostObserver;
use Workbench\App\Policies\PostPolicy;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Event::listen(PostPublished::class, NotifyEditors::class);

        Post::observe(PostObserver::class);

        Gate::define('editor', fn ($user) => true);
        Gate::policy(Post::class, PostPolicy::class);

        // A composer Bladestan cannot run (Blaze's real one is typed to Illuminate\View\View, which Bladestan's collector is not).
        view()->composer('posts.composed', function ($view): void {
            abort(500, 'this composer only runs for the real view');
        });
    }
}
