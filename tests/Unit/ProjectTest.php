<?php

declare(strict_types=1);

use Ohwhatnow\Quine\Project;

use function Orchestra\Testbench\workbench_path;

it('makes paths relative to the configured base path', function () {
    $project = Project::fromConfig();

    expect($project->relative(workbench_path('app/Models/Post.php')))->toBe('workbench/app/Models/Post.php')
        ->and($project->relative('/somewhere/else.php'))->toBe('/somewhere/else.php');
});

it('makes paths absolute against the configured base path', function () {
    $project = Project::fromConfig();

    expect($project->absolute('workbench/app/Models/Post.php'))->toBe(dirname(__DIR__, 2).'/workbench/app/Models/Post.php')
        ->and($project->absolute('/somewhere/else.php'))->toBe('/somewhere/else.php');
});
