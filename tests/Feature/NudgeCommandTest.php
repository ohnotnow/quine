<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Ohffs\Quine\Console\Commands\NudgeCommand;
use Ohffs\Quine\Differ;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use Ohffs\Quine\Rebuild;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Orchestra\Testbench\workbench_path;

final class FakeDiffer implements Differ
{
    public function __construct(private readonly string $diff) {}

    public function diff(string $relativePath): string
    {
        return $this->diff;
    }
}

it('prints the nullable nudges for a migration edit that adds nullable() to a foreign key', function () {
    app()->instance(Differ::class, new FakeDiffer("+            \$table->foreignId('author_id')->nullable()->constrained()->nullOnDelete();\n"));

    $this->artisan('quine:nudge', ['file' => 'workbench/database/migrations/0001_01_01_000003_create_comments_table.php'])
        ->expectsOutputToContain('Quine, after your edit to workbench/database/migrations/0001_01_01_000003_create_comments_table.php:')
        ->expectsOutputToContain('create_comments_table.php:13  Comment->author can be null')
        ->expectsOutputToContain('comments.blade.php:4  $comment->author->name breaks when author is null')
        ->expectsOutputToContain('no factory, seeder or test ever creates a Comment with a null author')
        ->assertSuccessful();
});

it('says nothing about an edit that only changes comments', function () {
    app()->instance(Differ::class, new FakeDiffer("-    // the old comment\n+    // the new comment\n+    /* and a block */\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toBe('');
});

it('says nothing about a docblock edit whose anchor text repeats the declaration it sits above', function () {
    app()->instance(Differ::class, new FakeDiffer(''));
    $stdin = fopen('php://memory', 'r+');
    fwrite($stdin, json_encode(['old' => "    public function isPublished(): bool\n", 'new' => "    /**\n     * Whether the post has gone out.\n     */\n    public function isPublished(): bool\n"]));
    rewind($stdin);
    $input = new ArrayInput(['file' => 'workbench/app/Models/Post.php', '--edit' => true]);
    $input->setStream($stdin);
    $output = new BufferedOutput;

    $command = app(NudgeCommand::class);
    $command->setLaravel(app());
    $command->run($input, $output);

    expect($output->fetch())->toBe('');
});

it('prints only the coverage for a body-only edit to a model, as fyi', function () {
    app()->instance(Differ::class, new FakeDiffer("-        return \$this->created_at !== null;\n+        return \$this->exists;\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toBe(implode("\n", [
        'Quine, after your edit to workbench/app/Models/Post.php:',
        'coverage data predates 1 test file (CommentPageTest.php): vendor/bin/pest --tia refreshes it, one normal suite run, then only the tests your change touches',
        '1 test file runs workbench/app/Models/Post.php, PostPageTest.php; vendor/bin/pest --tia runs just that',
    ])."\n");
});

it('names the observer, policy or listener chain only when the edit touches it', function () {
    app()->instance(Differ::class, new FakeDiffer("-        static::created(fn (Post \$post) => PostPublished::dispatch(\$post->id));\n+        static::created(fn (Post \$post) => PostPublished::dispatch(\$post->id, now()));\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    // Post's own booted() closure (Post.php:27) is in the file being edited, so it is not hidden and not printed.
    expect(Artisan::output())->toBe(implode("\n", [
        'Quine, after your edit to workbench/app/Models/Post.php:',
        'this dispatches PostPublished; NotifyEditors::handle runs on it, queued, so later and outside the request',
        'coverage data predates 1 test file (CommentPageTest.php): vendor/bin/pest --tia refreshes it, one normal suite run, then only the tests your change touches',
        '1 test file runs workbench/app/Models/Post.php, PostPageTest.php; vendor/bin/pest --tia runs just that',
    ])."\n");

    app()->instance(Differ::class, new FakeDiffer("+        static::saving(fn (Post \$post) => \$post->title = trim(\$post->title));\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nsaving a Post also runs Workbench\\App\\Observers\\PostObserver::saving, registered in the observer, not in this file\n")
        ->not->toContain('policy ->')
        ->not->toContain('dispatches');

    app()->instance(Differ::class, new FakeDiffer("+        Gate::policy(Post::class, PostPolicy::class);\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nWorkbench\\App\\Policies\\PostPolicy decides who may do this; it is not in this file\n")
        ->not->toContain('on saving');
});

it('names the templates reached through other classes only when one of them uses the method the edit changed', function () {
    app()->instance(Differ::class, new FakeDiffer("-    public function author(): BelongsTo\n+    public function author(): ?BelongsTo\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    // show.blade.php (rendered by PostController, which uses Post) reads $post->author; comments.blade.php comes through its include.
    // With no template symbol edges (the TestCase stubs Bladestan out), the template is found by name match.
    expect(Artisan::output())->toStartWith(implode("\n", [
        'Quine, after your edit to workbench/app/Models/Post.php:',
        'Post::author() changed shape; called from workbench/tests/Feature/PostPageTest.php:20, workbench/tests/Feature/PostPageTest.php:21: check each call still fits',
        'the template workbench/resources/views/posts/comments.blade.php shows this, and no test renders it: check it in the browser or write one',
    ])."\n")->toContain("\ntemplates that show this: workbench/resources/views/posts/show.blade.php. Each has a test that renders it; a test rendering a template is not a test of your change\n");
});

it('names the resolved consumers of a method the edit removed, with no heuristic hedge', function () {
    app()->instance(Differ::class, new FakeDiffer(implode("\n", [
        '@@ -28,6 +28,2 @@',
        '     }',
        ' ',
        '-    public function isPublished(): bool',
        '-    {',
        '-        return $this->created_at !== null;',
        '-    }',
        '-',
        '     public function author(): BelongsTo',
    ])."\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    // isPublished() is in no template, and the diff goes nowhere near the observer, policy or event.
    expect(Artisan::output())->toBe(implode("\n", [
        'Quine, after your edit to workbench/app/Models/Post.php:',
        'Post::isPublished() is gone but still called from workbench/app/Http/Controllers/PostController.php:12, workbench/app/Support/PostDigest.php:14, workbench/app/Support/PostSummary.php:14, workbench/tests/Feature/PostPageTest.php:14: those calls break',
        'Post::isPublished is used at workbench/app/Http/Controllers/PostController.php:12; that use ends up in the GET|HEAD /posts/{post} route, in PostController::show; coverage data predates newer test files for workbench/app/Http/Controllers/PostController.php',
        'Post::isPublished is used at workbench/app/Support/PostSummary.php:14; that use ends up in the GET|HEAD /posts/{post}/summary route, in PostSummaryController::show; the call chain is PostSummaryController::show -> PostSummary::line -> Post::isPublished; coverage data predates newer test files for workbench/app/Http/Controllers/PostSummaryController.php',
        'PostDigest::digest uses this, and nothing quine can see uses that: a dead end, or a string-keyed call it cannot follow',
        'coverage data predates 1 test file (CommentPageTest.php): vendor/bin/pest --tia refreshes it, one normal suite run, then only the tests your change touches',
        '1 test file runs workbench/app/Models/Post.php, PostPageTest.php; vendor/bin/pest --tia runs just that',
    ])."\n");
});

it('stops the walk at the configured depth and says so once', function () {
    config()->set('quine.reach.depth', 1);
    app()->instance(Differ::class, new FakeDiffer("-    public function isPublished(): bool\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nPost::isPublished is used at workbench/app/Http/Controllers/PostController.php:12; that use ends up in the GET|HEAD /posts/{post} route, in PostController::show; coverage data predates newer test files for workbench/app/Http/Controllers/PostController.php\n")
        ->toContain("\nstopped following Post::isPublished after 1 hop; php artisan quine:ask Post::isPublished lists the rest\n")
        ->not->toContain('PostSummaryController');
});

it('names the callers of a method whose signature the edit changed, and says nothing about a body-only edit', function () {
    app()->instance(Differ::class, new FakeDiffer(implode("\n", [
        '-    public function isPublished(): bool',
        '+    public function isPublished(?Carbon $at = null): bool',
        '     {',
        '-        return $this->created_at !== null;',
        '+        return $this->created_at !== null && ($at === null || $this->created_at <= $at);',
    ])."\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nPost::isPublished() changed shape; called from workbench/app/Http/Controllers/PostController.php:12, workbench/app/Support/PostDigest.php:14, workbench/app/Support/PostSummary.php:14, workbench/tests/Feature/PostPageTest.php:14: check each call still fits\n");

    app()->instance(Differ::class, new FakeDiffer("-        return \$this->created_at !== null;\n+        return \$this->exists;\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->not->toContain('isPublished');
});

it('says when a removed method has no consumer, and nothing when a changed one has none', function () {
    app()->instance(Differ::class, new FakeDiffer("-    public function tags(): BelongsToMany\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    $output = Artisan::output();

    expect($output)->toContain("\nPost::tags() is gone and nothing quine can see called it\n")
        ->and($output)->not->toContain('name match');

    app()->instance(Differ::class, new FakeDiffer("-    public function tags(): BelongsToMany\n+    public function tags(bool \$all = false): BelongsToMany\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->not->toContain('tags()');
});

it('takes the edit itself from stdin with --edit instead of asking git', function () {
    app()->instance(Differ::class, new FakeDiffer(''));
    $stdin = fopen('php://memory', 'r+');
    fwrite($stdin, json_encode(['old' => "    public function isPublished(): bool\n", 'new' => "    public function wasPublished(): bool\n"]));
    rewind($stdin);
    $input = new ArrayInput(['file' => 'workbench/app/Models/Post.php', '--edit' => true]);
    $input->setStream($stdin);
    $output = new BufferedOutput;

    $command = app(NudgeCommand::class);
    $command->setLaravel(app());
    $command->run($input, $output);

    expect($output->fetch())->toContain("\nPost::isPublished() is gone but still called from workbench/app/Http/Controllers/PostController.php:12, workbench/app/Support/PostDigest.php:14, workbench/app/Support/PostSummary.php:14, workbench/tests/Feature/PostPageTest.php:14: those calls break\n");
});

it('walks from a method whose body the edit changed, through the header the edit differ writes', function () {
    app()->instance(Differ::class, new FakeDiffer(''));
    $stdin = fopen('php://memory', 'r+');
    // The written file holds the new text: this edit turned === into !== inside isPublished().
    fwrite($stdin, json_encode(['old' => "        return \$this->created_at === null;\n", 'new' => "        return \$this->created_at !== null;\n"]));
    rewind($stdin);
    $input = new ArrayInput(['file' => 'workbench/app/Models/Post.php', '--edit' => true]);
    $input->setStream($stdin);
    $output = new BufferedOutput;

    $command = app(NudgeCommand::class);
    $command->setLaravel(app());
    $command->run($input, $output);

    expect($output->fetch())->toStartWith("Quine, after your edit to workbench/app/Models/Post.php:\nPost::isPublished is used at workbench/app/Http/Controllers/PostController.php:12; that use ends up in the GET|HEAD /posts/{post} route, in PostController::show; coverage data predates newer test files for workbench/app/Http/Controllers/PostController.php\n")
        ->toContain("\nPostDigest::digest uses this, and nothing quine can see uses that: a dead end, or a string-keyed call it cannot follow\n")
        ->not->toContain('isPublished() removed');
});

it('walks from a scope under the name it is called by, and treats a controller method no route names as a pass-through', function () {
    app()->instance(Differ::class, new FakeDiffer(implode("\n", [
        '@@ -66,1 +66,1 @@ public function scopePublished(Builder $query): void',
        '     {',
        '-        $query->whereNotNull(\'created_at\');',
        '+        $query->whereNotNull(\'published_at\');',
        '     }',
    ])."\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nPostController::published uses this, and nothing quine can see uses that: a dead end, or a string-keyed call it cannot follow\n")
        ->not->toContain('renders');
});

it('names the consumers of a removed scope or accessor under the name they consume it by', function () {
    app()->instance(Differ::class, new FakeDiffer("-    public function scopePublished(Builder \$query): void\n"));
    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nPost::scopePublished() is gone but still called from workbench/app/Http/Controllers/PostController.php:17: those calls break\n")
        ->not->toContain('no caller found');

    app()->instance(Differ::class, new FakeDiffer("-    public function getTitleLabelAttribute(): string\n"));
    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nPost::getTitleLabelAttribute() is gone but still called from workbench/app/Http/Controllers/PostController.php:27, workbench/app/Support/PostCache.php:19: those calls break\n");

    app()->instance(Differ::class, new FakeDiffer("-    protected function excerpt(): Attribute\n"));
    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nPost::excerpt() is gone but still called from workbench/app/Http/Controllers/PostController.php:27: those calls break\n");
});

it('prints the reach of a controller edit, which used to be silent', function () {
    app()->instance(Differ::class, new FakeDiffer("+        return view('posts.show', ['post' => \$post]);\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Http/Controllers/PostController.php']);

    expect(Artisan::output())->toStartWith("Quine, after your edit to workbench/app/Http/Controllers/PostController.php:\nthe template workbench/resources/views/posts/comments.blade.php shows this, and no test renders it: check it in the browser or write one\n")
        ->toContain("templates that show this: workbench/resources/views/posts/show.blade.php. Each has a test that renders it; a test rendering a template is not a test of your change\n")
        ->not->toContain('on created');
});

it('prints the template that embeds an edited Livewire component', function () {
    app()->instance(Differ::class, new FakeDiffer("+    public \$open = false;\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Livewire/CommentBox.php']);

    expect(Artisan::output())->toContain("the template workbench/resources/views/posts/comments.blade.php shows this, and no test renders it: check it in the browser or write one\n");
});

it('prints the templates around an edited template, and opens with fyi while the coverage data cannot say nobody runs it', function () {
    app()->instance(Differ::class, new FakeDiffer("+    <p>{{ \$comment->created_at }}</p>\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/resources/views/posts/comments.blade.php']);

    expect(Artisan::output())->toBe(implode("\n", [
        'Quine, after your edit to workbench/resources/views/posts/comments.blade.php:',
        'templates that show this: workbench/resources/views/posts/show.blade.php. Each has a test that renders it; a test rendering a template is not a test of your change',
        'coverage data predates 1 test file (CommentPageTest.php): vendor/bin/pest --tia refreshes it, one normal suite run, then only the tests your change touches',
    ])."\n");
});

it('opens with fyi when everything it reaches is tested and nothing hidden lives outside the file', function () {
    // A fresh tia cache that covers the controller and both templates it reaches, with every fixture test in it.
    $tia = dirname(config()->string('quine.graph_path')).'/tia-graph.json';
    mkdir(dirname($tia), 0755, true);
    file_put_contents($tia, json_encode([
        'files' => ['workbench/resources/views/posts/show.blade.php', 'workbench/resources/views/posts/comments.blade.php', 'workbench/app/Http/Controllers/PostController.php'],
        'edges' => ['workbench/tests/Feature/PostPageTest.php' => [0, 1, 2], 'workbench/tests/Feature/CommentPageTest.php' => [1]],
    ]));
    config()->set('quine.tia_graph', $tia);
    app()->instance(Differ::class, new FakeDiffer("+        abort_unless(\$post->isPublished(), 404);\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Http/Controllers/PostController.php']);

    expect(Artisan::output())->toBe(implode("\n", [
        'Quine, after your edit to workbench/app/Http/Controllers/PostController.php:',
        'templates that show this: workbench/resources/views/posts/show.blade.php, workbench/resources/views/posts/comments.blade.php. Each has a test that renders it; a test rendering a template is not a test of your change',
        '1 test file runs workbench/app/Http/Controllers/PostController.php, PostPageTest.php; vendor/bin/pest --tia runs just that',
    ])."\n");
});

it('prints nothing for a file the graph does not know', function () {
    app()->instance(Differ::class, new FakeDiffer("+    // a comment\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Concerns/Nothing.php']);

    expect(Artisan::output())->toBe('');
});

it('builds the graph when there is none, and answers from the saved one instead of rebuilding when the app has changed since', function () {
    app()->instance(Differ::class, new FakeDiffer("-        return \$this->created_at !== null;\n+        return \$this->exists;\n"));
    $project = app(Project::class);
    $rebuilds = 0;
    app()->instance(Rebuild::class, new Rebuild($project, function () use (&$rebuilds): int {
        $rebuilds++;

        return 1;
    }));
    $migration = workbench_path('database/migrations/0001_01_01_000001_create_authors_table.php');
    $originalMtime = filemtime($migration);

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);
    $fresh = Artisan::output();
    $first = Graph::load($project->graphPath)->meta;

    try {
        touch($migration, $originalMtime + 60);
        Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);
        $stale = Artisan::output();
        $second = Graph::load($project->graphPath)->meta;
    } finally {
        touch($migration, $originalMtime);
    }

    expect($fresh)->not->toContain('graph is stale')
        ->and($second['built_at'])->toBe($first['built_at'])
        ->and($second['fingerprint'])->toBe($first['fingerprint'])
        ->and($stale)->toBe($fresh)
        ->and($rebuilds)->toBe(1);
});

it('prints nothing for a file outside the project and survives a differ that throws', function () {
    app()->instance(Differ::class, new FakeDiffer(''));
    Artisan::call('quine:nudge', ['file' => '/somewhere/outside.php']);

    expect(Artisan::output())->toBe('');

    app()->instance(Differ::class, new class implements Differ
    {
        public function diff(string $relativePath): string
        {
            throw new RuntimeException('git exploded');
        }
    });

    $exit = Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Comment.php']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('can be null');
});

it('names the template lines that read a removed relation', function () {
    $this->withTemplates();
    app()->instance(Differ::class, new FakeDiffer("-    public function author(): BelongsTo\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Comment.php']);

    expect(Artisan::output())->toContain("\nComment::author() is gone but still called from workbench/app/Support/PostCache.php:28, workbench/app/Support/PostCache.php:35, workbench/app/Support/PostCache.php:36, workbench/resources/views/posts/comments.blade.php:4, workbench/resources/views/posts/comments.blade.php:5: those calls break\n");
});

it('falls back to the name match when the graph has no symbol edges for the class', function () {
    app()->instance(Differ::class, new FakeDiffer("-    public function isPublished(): bool\n"));

    Artisan::call('quine:update');
    $path = config()->string('quine.graph_path');
    $graph = Graph::load($path);
    $graph->edges = array_values(array_filter($graph->edges, fn (array $edge) => ! in_array($edge['kind'], ['calls', 'fetches'], true)));
    $graph->save($path);

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nPost::isPublished() is gone but still called from workbench/app/Http/Controllers/PostController.php:12, workbench/app/Support/PostDigest.php:14, workbench/app/Support/PostSummary.php:14, workbench/tests/Feature/PostPageTest.php:14: those calls break (matched by name, so check each is really this method)\n");
});

it('reaches a template through a symbol edge to the changed member, not by the name in its text', function () {
    $this->withTemplates();

    // show.blade.php:3 reads $post->author, and PostController renders it.
    app()->instance(Differ::class, new FakeDiffer("-    public function author(): BelongsTo\n"));
    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    // The walk reaches the template itself, with its trail and coverage, so the templates-reached note does not repeat it.
    expect(Artisan::output())->toContain('the template workbench/resources/views/posts/show.blade.php:3 (PostPageTest.php renders it) shows this; a test rendering a template is not a test of your change')
        ->not->toContain('templates reached: workbench/resources/views/posts/show.blade.php');

    // show.blade.php:3 reads $post->author->name: the word "name" is in its text, but the edge is
    // to Author::name. A name match for a removed Post::name() would list it; the edge test does not.
    app()->instance(Differ::class, new FakeDiffer("-    public function name(): string\n"));
    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    $output = Artisan::output();

    expect($output)->toContain("\nPost::name() is gone and nothing quine can see called it\n")
        ->and($output)->not->toContain('show.blade.php');
});

it('asserts that no test runs a file only when the coverage data is complete', function () {
    $project = app(Project::class);
    Artisan::call('quine:update', ['--quiet' => true]);
    $graph = Graph::load($project->graphPath);
    $graph->meta['coverage_missing_tests'] = [];
    $graph->save($project->graphPath);
    app()->instance(Differ::class, new FakeDiffer("+    <p>{{ \$comment->created_at }}</p>\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/resources/views/posts/comments.blade.php']);

    expect(Artisan::output())->toContain("\nno test runs workbench/resources/views/posts/comments.blade.php, by the coverage data\n")
        ->and(Artisan::output())->not->toContain('coverage data predates');
});
