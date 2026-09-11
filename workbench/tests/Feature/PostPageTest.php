<?php

use Workbench\App\Models\Post;

/*
 * A fixture test of the fixture app. Never run by the package suite; it exists
 * so the coverage overlay has a test file that the tia fixture names.
 */
it('renders the post page', function () {
    expect(true)->toBeTrue();
});

it('hides an unpublished post', function () {
    expect(Post::factory()->make()->isPublished())->toBeFalse();
});

it('shows the author when there is one', function () {
    $post = Post::factory()->make();

    expect($post->author?->name)->toBeNull()
        ->and($post->author->name)->toBeNull();
});
