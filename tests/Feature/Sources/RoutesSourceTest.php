<?php

declare(strict_types=1);

use Workbench\App\Http\Controllers\PostController;

it('emits a route edge from the route to its controller with the middleware', function () {
    expect(updatedGraph()->edgesTo(PostController::class, 'route'))->toContain([
        'from' => 'route GET|HEAD /posts/{post} [posts.show]',
        'to' => PostController::class,
        'kind' => 'route',
        'label' => 'show web',
        'at' => null,
    ]);
});
