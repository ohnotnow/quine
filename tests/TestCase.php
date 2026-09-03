<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Tests;

use Ohwhatnow\Quine\QuineServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            QuineServiceProvider::class,
        ];
    }
}
