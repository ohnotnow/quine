<?php

declare(strict_types=1);

use Workbench\App\Events\PostPublished;
use Workbench\App\Models\Post;

it('emits a uses edge for a static class reference and never for a bare namespace', function () {
    $graph = updatedGraph();
    $uses = array_filter($graph->edges, fn (array $edge) => $edge['kind'] === 'uses');

    $unknown = array_filter(array_column($uses, 'to'), fn (string $to) => ! (class_exists($to) || interface_exists($to) || enum_exists($to)));

    expect(array_column($graph->edgesFrom(Post::class, 'uses'), 'to'))->toContain(PostPublished::class)
        ->and(array_column($uses, 'to'))->not->toContain('Workbench\App\Models')
        ->and($unknown)->toBe([]);
});
