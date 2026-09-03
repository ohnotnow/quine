<?php

declare(strict_types=1);

use Workbench\App\Models\Post;
use Workbench\App\Policies\PostPolicy;

it('emits a gate edge to the closure that defines it', function () {
    expect(updatedGraph()->edgesFrom('gate:editor', 'gate'))->toContain([
        'from' => 'gate:editor',
        'to' => 'closure workbench/app/Providers/WorkbenchServiceProvider.php:33',
        'kind' => 'gate',
        'label' => 'defined by',
        'at' => null,
    ]);
});

it('emits a policy edge from the model to its policy', function () {
    expect(updatedGraph()->edgesFrom(Post::class, 'policy'))->toContain([
        'from' => Post::class,
        'to' => PostPolicy::class,
        'kind' => 'policy',
        'label' => 'policy',
        'at' => null,
    ]);
});
