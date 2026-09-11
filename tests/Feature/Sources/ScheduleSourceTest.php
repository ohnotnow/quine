<?php

declare(strict_types=1);

use Workbench\App\Console\Commands\AnnouncePosts;

it('emits one schedule edge per scheduled task with its cron expression', function () {
    expect(updatedGraph()->edgesFrom('schedule', 'schedule'))->toContain([
        'from' => 'schedule',
        'to' => 'inspire',
        'kind' => 'schedule',
        'label' => '0 0 * * *',
        'at' => null,
    ]);
});

it('points a scheduled command at its class, so an edit to the class knows it runs on a schedule', function () {
    $graph = updatedGraph();

    expect($graph->edgesFrom('schedule', 'schedule'))->toContain([
        'from' => 'schedule',
        'to' => AnnouncePosts::class,
        'kind' => 'schedule',
        'label' => '0 * * * *',
        'at' => null,
    ])->and(array_column($graph->edgesFrom('schedule', 'schedule'), 'to'))->not->toContain('posts:announce');
});
