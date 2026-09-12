<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Ohffs\Quine\Console\Commands\AskCommand;
use Ohffs\Quine\Console\Commands\NudgeCommand;
use Ohffs\Quine\Console\Commands\UpdateCommand;
use Ohffs\Quine\Recipes\Registry;
use Ohffs\Quine\Sources\BladeSource;
use Ohffs\Quine\Sources\CoverageSource;
use Ohffs\Quine\Sources\GatesSource;
use Ohffs\Quine\Sources\ListenersSource;
use Ohffs\Quine\Sources\ModelEventsSource;
use Ohffs\Quine\Sources\ModelsSource;
use Ohffs\Quine\Sources\RendersSource;
use Ohffs\Quine\Sources\RoutesSource;
use Ohffs\Quine\Sources\ScheduleSource;
use Ohffs\Quine\Sources\SchemaSource;
use Ohffs\Quine\Sources\SymbolsSource;
use Ohffs\Quine\Sources\UsesSource;
use Ohffs\Quine\Support\Bladestan;

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
        $this->app->bind(Nudger::class, fn (Application $app) => new Nudger($app->make(Project::class), $app->make(Registry::class)));
        $this->app->singleton(Bladestan::class);

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
                $app->make(SymbolsSource::class),
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
