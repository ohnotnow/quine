<?php

declare(strict_types=1);

use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyEditors;

it('emits an event edge to a queued listener', function () {
    expect(updatedGraph()->edgesFrom(PostPublished::class, 'event'))->toContain([
        'from' => PostPublished::class,
        'to' => NotifyEditors::class.'@handle',
        'kind' => 'event',
        'label' => 'queued listener',
        'at' => null,
    ]);
});

it('drops event edges whose event and listener are both outside the app namespace', function () {
    $events = array_filter(updatedGraph()->edges, fn (array $edge) => $edge['kind'] === 'event');

    expect($events)->not->toBeEmpty();

    foreach ($events as $edge) {
        expect(str_starts_with($edge['from'], 'Workbench\App\\') || str_starts_with($edge['to'], 'Workbench\App\\'))->toBeTrue();
    }
});
