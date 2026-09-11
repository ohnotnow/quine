<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyEditors;
use Workbench\App\Models\Author;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;
use Workbench\App\Policies\PostPolicy;

it('prints the labelled neighbourhood of a model in both directions with breadcrumbs', function () {
    $this->artisan('quine:update')->assertSuccessful();

    $this->artisan('quine:ask', ['node' => Comment::class])
        ->expectsOutputToContain('-> [relation: author() BelongsTo] '.Author::class.'  workbench/app/Models/Comment.php:25')
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

it('prints the nudges of models one relation hop away, so a nullable key on a neighbour is not missed', function () {
    $this->artisan('quine:update')->assertSuccessful();

    // Post has no nullable belongsTo of its own; Comment, one hop away, does.
    $this->artisan('quine:ask', ['node' => Post::class])
        ->expectsOutputToContain('no factory, seeder or test ever creates a Comment with a null author')
        ->doesntExpectOutputToContain('nothing to add')
        ->assertSuccessful();
});

it('stops the nudge fan-out at one hop', function () {
    $this->artisan('quine:update')->assertSuccessful();

    // Tag reaches Comment only through Post: two hops.
    $this->artisan('quine:ask', ['node' => Tag::class])
        ->doesntExpectOutputToContain('null author')
        ->expectsOutputToContain('nothing to add')
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

    Artisan::call('quine:ask', ['node' => Post::class, '--full' => true]);
    $output = Artisan::output();

    expect(substr_count($output, '-> [uses: static reference (heuristic)] '.PostPublished::class))->toBe(1)
        ->and(substr_count($output, '-> [event: queued listener] '.NotifyEditors::class.'@handle'))->toBe(1)
        ->and($output)->toContain('    via '.PostPublished::class.":\n        -> [event: queued listener] ".NotifyEditors::class.'@handle')
        ->and($output)->not->toContain('via '.Post::class.':');
});

it('collapses uses edges to a count by default, but still walks through them', function () {
    $this->artisan('quine:update')->assertSuccessful();

    Artisan::call('quine:ask', ['node' => Post::class]);
    $output = Artisan::output();

    expect($output)->toContain('<- referenced by 11 classes (uses; --full lists them)')
        ->and($output)->toContain('-> references 2 classes (uses; --full lists them)')
        ->and($output)->not->toContain('PostController.php:6')
        ->and($output)->toContain('    via '.PostPublished::class.":\n        -> [event: queued listener] ".NotifyEditors::class.'@handle')
        // PostPolicy's only edge beyond the root is a uses edge: a via block holding nothing but a count is padding.
        ->and($output)->not->toContain('via '.PostPolicy::class.':');
});

it('lists every uses edge in place with --full, after the surprising kinds', function () {
    $this->artisan('quine:update')->assertSuccessful();

    Artisan::call('quine:ask', ['node' => Post::class, '--full' => true]);
    $output = Artisan::output();

    expect($output)->toContain('<- [uses: static reference (heuristic)] Workbench\\App\\Http\\Controllers\\PostController  workbench/app/Http/Controllers/PostController.php:6')
        ->and($output)->not->toContain('referenced by')
        ->and($output)->toContain('via '.PostPolicy::class.':')
        ->and(strpos($output, '[model-event: created]'))->toBeLessThan(strpos($output, '[uses: static reference'));
});

it('builds the graph first when there is none on disk', function () {
    expect(config()->string('quine.graph_path'))->not->toBeFile();

    $this->artisan('quine:ask', ['node' => Comment::class])
        ->expectsOutputToContain('built it first')
        ->assertSuccessful();

    expect(config()->string('quine.graph_path'))->toBeFile();
});

it('prints the consumers of one member when asked for Class::member', function () {
    $this->artisan('quine:update')->assertSuccessful();

    Artisan::call('quine:ask', ['node' => 'Post::isPublished']);
    $output = Artisan::output();

    expect($output)->toContain('CONSUMERS')
        ->and($output)->toContain(Post::class.'::isPublished')
        ->and($output)->toContain('<- [calls: calls isPublished()] Workbench\App\Http\Controllers\PostController::show  workbench/app/Http/Controllers/PostController.php:12')
        ->and($output)->toContain('REACHES')
        ->and($output)->toContain('    reaches route GET|HEAD /posts/{post}/summary (PostSummaryController::show) via Post::isPublished -> PostSummary::line -> PostSummaryController::show (at workbench/app/Support/PostSummary.php:14); no test covers workbench/app/Http/Controllers/PostSummaryController.php')
        ->and($output)->toContain('    reached PostDigest::digest, nothing found that uses it')
        ->and($output)->not->toContain('NEIGHBOURHOOD');
});

it('shows the sink a fetched member feeds in the consumer line', function () {
    $this->artisan('quine:update')->assertSuccessful();

    Artisan::call('quine:ask', ['node' => 'Post::title']);

    expect(Artisan::output())->toContain('<- [fetches: fetches title (cache key)] Workbench\App\Support\PostCache::title  workbench/app/Support/PostCache.php:19');
});

it('accepts the arrow form for a property and lists the template that reads it', function () {
    $this->withTemplates();
    $this->artisan('quine:update')->assertSuccessful();

    Artisan::call('quine:ask', ['node' => 'Comment->author']);

    expect(Artisan::output())->toContain('CONSUMERS')
        ->toContain('<- [fetches: fetches author] workbench/resources/views/posts/comments.blade.php  workbench/resources/views/posts/comments.blade.php:4');
});

it('says when nothing consumes a member, and names the members that are consumed', function () {
    $this->artisan('quine:update')->assertSuccessful();

    $this->artisan('quine:ask', ['node' => 'Post::nope'])
        ->expectsOutputToContain('nothing in the graph consumes '.Post::class.'::nope')
        ->expectsOutputToContain('isPublished')
        ->assertFailed();
});

it('summarises the consumed members of a class on one line each, without walking through them', function () {
    $this->artisan('quine:update')->assertSuccessful();

    Artisan::call('quine:ask', ['node' => Post::class, '--depth' => 3]);
    $output = Artisan::output();

    expect($output)->toContain('isPublished() called from 4 places')
        ->and($output)->toContain('quine:ask Post::')
        ->and($output)->toContain('<- [relation: posts() HasMany] '.Author::class)
        ->and($output)->not->toContain('via '.Post::class.'::isPublished:')
        ->and($output)->not->toContain('    <- [calls: calls isPublished()]');
});
