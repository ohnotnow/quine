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
        "workbench/database/migrations/0001_01_01_000003_create_comments_table.php:13  Comment->author can be null; the migration says so: \$table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();",
        'workbench/resources/views/posts/comments.blade.php:4  $comment->author->name breaks when author is null, and it can be (the variable is matched to the model by name, so check it is a Comment)',
        'workbench/app/Support/PostCache.php:28  $comment->author->id breaks when author is null, and it can be (the variable is matched to the model by name, so check it is a Comment)',
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

    expect($reasons)->toHaveCount(3)
        ->and(implode("\n", $reasons))->toContain('can be null;')->toContain('breaks when')->not->toContain('no factory, seeder or test');
});

it('stays silent for a model edit whose diff does not touch the nullable relation', function () {
    $model = 'workbench/app/Models/Comment.php';
    $diff = "--- a/$model\n+++ b/$model\n@@ -34,5 +34,5 @@\n     public function getExcerptAttribute(): string\n     {\n-        return Str::limit(\$this->body, 40);\n+        return Str::limit(\$this->body, 50);\n     }\n";

    expect(app(NullableBelongsTo::class)->nudges(Change::forFile($model, $diff), updatedGraph()))->toBe([]);
});

it('nudges for a model edit in or beside the relation method, or naming its column', function () {
    $graph = updatedGraph();
    $model = 'workbench/app/Models/Comment.php';
    $fromNode = array_map('strval', app(NullableBelongsTo::class)->nudges(Change::forNode(Comment::class), $graph));

    // An edit inside the method body: git's context lines carry the declaration.
    $insideMethod = "--- a/$model\n+++ b/$model\n@@ -24,4 +24,4 @@\n     public function author(): BelongsTo\n     {\n-        return \$this->belongsTo(Author::class);\n+        return \$this->belongsTo(Author::class)->withDefault();\n     }\n";
    $namesColumn = "--- a/$model\n+++ b/$model\n@@ -12,0 +13,1 @@\n+    protected \$fillable = ['author_id', 'body'];\n";

    expect(array_map('strval', app(NullableBelongsTo::class)->nudges(Change::forFile($model, $insideMethod), $graph)))->toBe($fromNode)
        ->and(array_map('strval', app(NullableBelongsTo::class)->nudges(Change::forFile($model, $namesColumn), $graph)))->toBe($fromNode)
        ->and($fromNode)->not->toBeEmpty();
});

it('stays silent for a model edit in the method above the relation, even when context lines show the relation declaration', function () {
    $model = 'workbench/app/Models/Comment.php';
    $diff = "--- a/$model\n+++ b/$model\n@@ -19,7 +19,7 @@ protected static function newFactory(): CommentFactory\n     protected static function newFactory(): CommentFactory\n     {\n-        return CommentFactory::new();\n+        return CommentFactory::new()->count(1);\n     }\n \n     /** @return BelongsTo<Author, \$this> */\n     public function author(): BelongsTo\n";

    expect(app(NullableBelongsTo::class)->nudges(Change::forFile($model, $diff), updatedGraph()))->toBe([]);
});

it('nudges for a template edit that reads through a nullable relation, from the file as it now is', function () {
    $template = 'workbench/resources/views/posts/comments.blade.php';
    $diff = "--- a/$template\n+++ b/$template\n@@ -4,1 +4,1 @@\n-            <strong>{{ \$comment->author->name }}</strong>\n+            <strong>{{ \$comment->author->name }}!</strong>\n";

    expect(array_map('strval', app(NullableBelongsTo::class)->nudges(Change::forFile($template, $diff), updatedGraph())))->toBe([
        'workbench/resources/views/posts/comments.blade.php:4  $comment->author->name breaks when author is null, and it can be (the variable is matched to the model by name, so check it is a Comment)',
        'workbench/app/Models/Comment.php  no factory, seeder or test ever creates a Comment with a null author: a green suite proves nothing about that path',
    ]);
});

it('stays silent for a template edit that does not read through the nullable relation', function () {
    $template = 'workbench/resources/views/posts/comments.blade.php';
    $diff = "--- a/$template\n+++ b/$template\n@@ -6,1 +6,1 @@\n-            <p>{{ \$comment->excerpt }}</p>\n+            <p>{{ \$comment->excerpt }}.</p>\n";

    expect(app(NullableBelongsTo::class)->nudges(Change::forFile($template, $diff), updatedGraph()))->toBe([]);
});

it('stays silent for a file that is neither a migration nor a model', function () {
    expect(app(NullableBelongsTo::class)->nudges(Change::forFile('workbench/app/Http/Controllers/PostController.php', ''), updatedGraph()))->toBe([]);
});

it('reports a PHP read only where PHPStan saw the receiver still nullable: not inside a guard, not on an untyped variable', function () {
    $nudges = array_map('strval', app(NullableBelongsTo::class)->nudges(Change::forNode(Comment::class), updatedGraph()));

    expect(implode("\n", $nudges))
        ->toContain('workbench/app/Support/PostCache.php:28  $comment->author->id breaks when author is null')
        ->not->toContain('workbench/app/Support/PostCache.php:36')
        ->not->toContain('workbench/app/Support/PostCache.php:45');
});
