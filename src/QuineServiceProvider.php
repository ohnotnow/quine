<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Ohwhatnow\Quine\Console\Commands\AskCommand;
use Ohwhatnow\Quine\Console\Commands\NudgeCommand;
use Ohwhatnow\Quine\Console\Commands\UpdateCommand;
use Ohwhatnow\Quine\Recipes\Registry;
use Ohwhatnow\Quine\Sources\BladeSource;
use Ohwhatnow\Quine\Sources\CoverageSource;
use Ohwhatnow\Quine\Sources\GatesSource;
use Ohwhatnow\Quine\Sources\ListenersSource;
use Ohwhatnow\Quine\Sources\ModelEventsSource;
use Ohwhatnow\Quine\Sources\ModelsSource;
use Ohwhatnow\Quine\Sources\RendersSource;
use Ohwhatnow\Quine\Sources\RoutesSource;
use Ohwhatnow\Quine\Sources\ScheduleSource;
use Ohwhatnow\Quine\Sources\SchemaSource;
use Ohwhatnow\Quine\Sources\UsesSource;

class QuineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/quine.php', 'quine');

        $this->app->bind(Project::class, fn () => Project::fromConfig());

        $this->app->bind(Registry::class, fn (Application $app) => Registry::fromConfig($app));

        $this->app->bind(Differ::class, GitDiffer::class);

        $this->app->bind(GraphBuilder::class, fn (Application $app) => new GraphBuilder(
            $app->make(Project::class),
            [
                $app->make(SchemaSource::class),
                $app->make(ModelsSource::class),
                $app->make(ModelEventsSource::class),
                $app->make(ListenersSource::class),
                $app->make(GatesSource::class),
                $app->make(ScheduleSource::class),
                $app->make(RoutesSource::class),
                $app->make(RendersSource::class),
                $app->make(UsesSource::class),
                $app->make(BladeSource::class),
                $app->make(CoverageSource::class),
            ],
        ));
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

        $this->commands([
            UpdateCommand::class,
            AskCommand::class,
            NudgeCommand::class,
        ]);
    }
}
