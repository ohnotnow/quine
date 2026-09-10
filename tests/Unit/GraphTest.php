<?php

declare(strict_types=1);

use Ohffs\Quine\Graph;

it('round-trips an edge through save and load', function () {
    $path = dirname(config()->string('quine.graph_path')).'/roundtrip.json';

    $graph = new Graph;
    $graph->edge('Workbench\App\Models\Comment', 'Workbench\App\Models\Author', 'relation', 'author (belongsTo)', 'workbench/app/Models/Comment.php:22');
    $graph->save($path);

    expect(Graph::load($path)->edges)->toBe([[
        'from' => 'Workbench\App\Models\Comment',
        'to' => 'Workbench\App\Models\Author',
        'kind' => 'relation',
        'label' => 'author (belongsTo)',
        'at' => 'workbench/app/Models/Comment.php:22',
    ]]);
});

it('keeps one copy of an edge added twice', function () {
    $graph = new Graph;
    $graph->edge('schedule', 'inspire', 'schedule', '0 0 * * *', null);
    $graph->edge('schedule', 'inspire', 'schedule', '0 0 * * *', null);

    expect($graph->edges)->toHaveCount(1);
});
