<?php

declare(strict_types=1);

use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Run quine:update against the workbench and load the graph it wrote.
 */
function updatedGraph(): Graph
{
    test()->artisan('quine:update')->assertSuccessful();

    return Graph::load(config()->string('quine.graph_path'));
}
