<?php

declare(strict_types=1);

use Ohffs\Quine\Change;
use Ohffs\Quine\Nudge;
use Ohffs\Quine\Recipes\NullableBelongsTo;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Post;

it('nudges about the migration line, the unguarded read and the fixture gap for a nullable belongsTo', function () {
    $nudges = app(NullableBelongsTo::class)->nudges(Change::forNode(Comment::class), updatedGraph());

    expect(array_map('strval', $nudges))->toBe([
        "workbench/database/migrations/0001_01_01_000003_create_comments_table.php:13  Comment->author can be null: \$table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();",
        'workbench/resources/views/posts/comments.blade.php:4  reads $comment->author->name without null-safety, but Comment->author can be null (variable matched by name, heuristic)',
        'workbench/app/Models/Comment.php  no factory, seeder or test ever creates a Comment with a null author: a green suite proves nothing about that path',
    ]);
});

it('stays silent for a model whose belongsTo is not nullable', function () {
    expect(app(NullableBelongsTo::class)->nudges(Change::forNode(Post::class), updatedGraph()))->toBe([]);
});

it('gives the same nudges for a migration edit that adds nullable() to the foreign key', function () {
    $graph = updatedGraph();
    $migration = 'workbench/database/migrations/0001_01_01_000003_create_comments_table.php';
    $diff = "--- a/$migration\n+++ b/$migration\n-            \$table->foreignId('author_id')->constrained();\n+            \$table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();\n";

    $fromFile = app(NullableBelongsTo::class)->nudges(Change::forFile($migration, $diff), $graph);
    $fromNode = app(NullableBelongsTo::class)->nudges(Change::forNode(Comment::class), $graph);

    expect(array_map('strval', $fromFile))->toBe(array_map('strval', $fromNode))->not->toBeEmpty();
});

it('drops the fixture-gap nudge once a fixture produces the null state', function () {
    $tests = dirname(config()->string('quine.graph_path')).'/tests';
    mkdir($tests, 0755, true);
    file_put_contents($tests.'/OrphanCommentTest.php', "<?php\n\nit('has no author', fn () => Comment::factory()->create(['author_id' => null]));\n");
    config()->set('quine.paths.tests', $tests);

    $reasons = array_map(fn (Nudge $nudge) => $nudge->reason, app(NullableBelongsTo::class)->nudges(Change::forNode(Comment::class), updatedGraph()));

    expect($reasons)->toHaveCount(2)
        ->and(implode("\n", $reasons))->toContain('can be null:')->toContain('without null-safety')->not->toContain('no factory, seeder or test');
});

it('stays silent for a file that is neither a migration nor a model', function () {
    expect(app(NullableBelongsTo::class)->nudges(Change::forFile('workbench/app/Http/Controllers/PostController.php', ''), updatedGraph()))->toBe([]);
});
