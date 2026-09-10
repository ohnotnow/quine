<?php

declare(strict_types=1);

namespace Ohffs\Quine\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

use function Orchestra\Testbench\workbench_path;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('quine.base_path', dirname(__DIR__));
        $app['config']->set('quine.namespace', 'Workbench\\App\\');
        $app['config']->set('quine.paths.app', workbench_path('app'));
        $app['config']->set('quine.paths.migrations', workbench_path('database/migrations'));
        $app['config']->set('quine.paths.views', [workbench_path('resources/views')]);
        $app['config']->set('quine.paths.tests', workbench_path('tests'));
        $app['config']->set('quine.paths.fixtures', [workbench_path('database/factories')]);
        $app['config']->set('quine.graph_path', sys_get_temp_dir().'/quine-test-'.uniqid().'/graph.json');
        $app['config']->set('quine.tia_graph', __DIR__.'/fixtures/tia-graph.json');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname(config()->string('quine.graph_path')));

        parent::tearDown();
    }
}
