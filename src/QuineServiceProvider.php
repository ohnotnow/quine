<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine;

use Illuminate\Support\ServiceProvider;
use Ohwhatnow\Quine\Console\Commands\QuineCommand;

class QuineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/quine.php', 'quine');

        $this->app->singleton(Quine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/quine.php' => config_path('quine.php'),
        ], ['quine', 'quine-config']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/quine'),
        ], ['quine', 'quine-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['quine', 'quine-migrations']);

        $this->commands([
            QuineCommand::class,
        ]);
    }
}
