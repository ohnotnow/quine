<?php

declare(strict_types=1);

use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Observers\PostObserver;

it('emits a model-event edge to the closure registered in booted()', function () {
    expect(updatedGraph()->edgesFrom(Post::class, 'model-event'))->toContain([
        'from' => Post::class,
        'to' => 'closure workbench/app/Models/Post.php:22',
        'kind' => 'model-event',
        'label' => 'created',
        'at' => null,
    ]);
});

it('emits no model-event edges for models without hooks or for classes outside the app', function () {
    $graph = updatedGraph();
    $modelEvents = array_filter($graph->edges, fn (array $edge) => $edge['kind'] === 'model-event');

    expect($graph->edgesFrom(Comment::class, 'model-event'))->toBe([])
        ->and(array_column($modelEvents, 'from'))->each->toBeIn(array_keys($graph->models));
});

it('emits a model-event edge to an observer method', function () {
    expect(updatedGraph()->edgesFrom(Post::class, 'model-event'))->toContain([
        'from' => Post::class,
        'to' => PostObserver::class.'@saving',
        'kind' => 'model-event',
        'label' => 'saving',
        'at' => null,
    ]);
});
