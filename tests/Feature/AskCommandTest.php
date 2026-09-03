<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyEditors;
use Workbench\App\Models\Author;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;

it('prints the labelled neighbourhood of a model in both directions with breadcrumbs', function () {
    $this->artisan('quine:update')->assertSuccessful();

    $this->artisan('quine:ask', ['node' => Comment::class])
        ->expectsOutputToContain('-> [relation: author() BelongsTo] '.Author::class.'  workbench/app/Models/Comment.php:24')
        ->expectsOutputToContain('<- [relation: comments() HasMany] '.Post::class)
        ->assertSuccessful();
});

it('prints the recipe nudges for the node under NUDGES', function () {
    $this->artisan('quine:update')->assertSuccessful();

    $this->artisan('quine:ask', ['node' => Comment::class])
        ->expectsOutputToContain('NUDGES')
        ->expectsOutputToContain('workbench/resources/views/posts/comments.blade.php:4  reads $comment->author->name without null-safety')
        ->expectsOutputToContain('no factory, seeder or test ever creates a Comment with a null author')
        ->assertSuccessful();
});

it('resolves a class basename, and fails plainly when nothing matches', function () {
    $this->artisan('quine:update')->assertSuccessful();

    $this->artisan('quine:ask', ['node' => 'Comment'])
        ->expectsOutputToContain('-> [relation: author() BelongsTo] '.Author::class)
        ->assertSuccessful();

    $this->artisan('quine:ask', ['node' => 'Nope'])
        ->expectsOutputToContain('nothing in the graph matches Nope')
        ->assertFailed();
});

it('reaches a queued listener two hops away through the event, printing each edge once under the node it hangs off', function () {
    $this->artisan('quine:update')->assertSuccessful();

    Artisan::call('quine:ask', ['node' => Post::class]);
    $output = Artisan::output();

    expect(substr_count($output, '-> [uses: uses (heuristic: static reference)] '.PostPublished::class))->toBe(1)
        ->and(substr_count($output, '-> [event: queued listener] '.NotifyEditors::class.'@handle'))->toBe(1)
        ->and($output)->toContain('    via '.PostPublished::class.":\n        -> [event: queued listener] ".NotifyEditors::class.'@handle')
        ->and($output)->not->toContain('via '.Post::class.':');
});

it('builds the graph first when there is none on disk', function () {
    expect(config()->string('quine.graph_path'))->not->toBeFile();

    $this->artisan('quine:ask', ['node' => Comment::class])
        ->expectsOutputToContain('built it first')
        ->assertSuccessful();

    expect(config()->string('quine.graph_path'))->toBeFile();
});
