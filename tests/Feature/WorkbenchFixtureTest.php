<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyEditors;
use Workbench\App\Models\Author;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

it('gives a comment an author by default', function () {
    $comment = Comment::factory()->create();

    expect($comment->author)->toBeInstanceOf(Author::class);
});

it('lets a comment have no author', function () {
    $comment = Comment::factory()->create(['author_id' => null]);

    expect($comment->fresh()->author)->toBeNull();
});

it('dispatches PostPublished when a post is created', function () {
    Event::fake([PostPublished::class]);

    $post = Post::factory()->create();

    Event::assertDispatched(PostPublished::class, fn (PostPublished $event) => $event->postId === $post->id);
});

it('renders the post page with editor tools for an author', function () {
    $post = Post::factory()->create();
    $comment = Comment::factory()->create(['post_id' => $post->id]);

    $this->actingAs($post->author)
        ->get(route('posts.show', $post))
        ->assertOk()
        ->assertSee($post->author->name)
        ->assertSee($comment->author->name)
        ->assertSee('editor tools');
});

it('lets a post have tags', function () {
    $post = Post::factory()->create();
    $tag = Tag::factory()->create();

    $post->tags()->attach($tag);

    expect($post->fresh()->tags->pluck('id')->all())->toBe([$tag->id]);
});

it('queues NotifyEditors for PostPublished', function () {
    expect(Event::getRawListeners()[PostPublished::class] ?? [])->toContain(NotifyEditors::class)
        ->and(new NotifyEditors)->toBeInstanceOf(ShouldQueue::class);
});

it('schedules the inspire command daily', function () {
    $inspire = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_ends_with($event->command ?? '', ' inspire'));

    expect($inspire)->not->toBeNull()
        ->and($inspire->expression)->toBe('0 0 * * *');
});
