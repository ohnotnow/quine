<?php

declare(strict_types=1);

use Workbench\App\Http\Controllers\DraftController;
use Workbench\App\Http\Controllers\PostController;

it('emits a renders edge from a class to the view file it renders', function () {
    expect(updatedGraph()->edgesFrom(PostController::class, 'renders'))->toContain([
        'from' => PostController::class,
        'to' => 'workbench/resources/views/posts/show.blade.php',
        'kind' => 'renders',
        'label' => 'posts.show',
        'at' => 'workbench/app/Http/Controllers/PostController.php:12',
    ]);
});

it('marks a view that cannot be found as missing instead of failing', function () {
    $edges = updatedGraph()->edgesFrom(DraftController::class, 'renders');

    expect(array_column($edges, 'to'))->toBe(['view:does.not.exist (missing)']);
});
