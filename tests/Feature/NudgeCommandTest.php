<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Ohffs\Quine\Differ;
use Ohffs\Quine\Fingerprint;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;

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
        ->expectsOutputToContain('Quine: hang on. workbench/database/migrations/0001_01_01_000003_create_comments_table.php')
        ->expectsOutputToContain('create_comments_table.php:13  Comment->author can be null')
        ->expectsOutputToContain('comments.blade.php:4  reads $comment->author->name without null-safety')
        ->expectsOutputToContain('no factory, seeder or test ever creates a Comment with a null author')
        ->assertSuccessful();
});

it('opens with hang on when there is a gap, and says only what lives outside the edited model', function () {
    app()->instance(Differ::class, new FakeDiffer(''));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    // Post's own booted() closure (Post.php:22) is in the file being edited, so it is not hidden and not printed.
    expect(Artisan::output())->toBe(implode("\n", [
        'Quine: hang on. workbench/app/Models/Post.php',
        'reaches workbench/resources/views/posts/comments.blade.php: no test renders this',
        'on saving -> Workbench\\App\\Observers\\PostObserver@saving',
        'policy -> Workbench\\App\\Policies\\PostPolicy',
        'dispatches Workbench\\App\\Events\\PostPublished -> Workbench\\App\\Listeners\\NotifyEditors@handle (queued listener)',
        'templates reached: workbench/resources/views/posts/show.blade.php (a test renders each; whether it exercises your change is yours to check)',
        'tia cache is stale: 1 test files are not in it (CommentPageTest.php): re-run vendor/bin/pest --tia',
        '1 test file covers this file: vendor/bin/pest --tia runs it',
    ])."\n");
});

it('prints the reach of a controller edit, which used to be silent', function () {
    app()->instance(Differ::class, new FakeDiffer("+        return view('posts.show', ['post' => \$post]);\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Http/Controllers/PostController.php']);

    expect(Artisan::output())->toStartWith("Quine: hang on. workbench/app/Http/Controllers/PostController.php\nreaches workbench/resources/views/posts/comments.blade.php: no test renders this\nno test covers this file\n")
        ->toContain("templates reached: workbench/resources/views/posts/show.blade.php (a test renders each; whether it exercises your change is yours to check)\n")
        ->not->toContain('on created');
});

it('prints the template that embeds an edited Livewire component', function () {
    app()->instance(Differ::class, new FakeDiffer(''));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Livewire/CommentBox.php']);

    expect(Artisan::output())->toContain("reaches workbench/resources/views/posts/comments.blade.php: no test renders this\n");
});

it('prints the templates around an edited template and its own coverage, with no hidden-edges block', function () {
    app()->instance(Differ::class, new FakeDiffer(''));

    Artisan::call('quine:nudge', ['file' => 'workbench/resources/views/posts/comments.blade.php']);

    expect(Artisan::output())->toBe(implode("\n", [
        'Quine: hang on. workbench/resources/views/posts/comments.blade.php',
        'no test covers this file',
        'templates reached: workbench/resources/views/posts/show.blade.php (a test renders each; whether it exercises your change is yours to check)',
        'tia cache is stale: 1 test files are not in it (CommentPageTest.php): re-run vendor/bin/pest --tia',
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
    app()->instance(Differ::class, new FakeDiffer(''));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Http/Controllers/PostController.php']);

    expect(Artisan::output())->toBe(implode("\n", [
        'Quine: fyi. workbench/app/Http/Controllers/PostController.php',
        'templates reached: workbench/resources/views/posts/show.blade.php, workbench/resources/views/posts/comments.blade.php (a test renders each; whether it exercises your change is yours to check)',
        '1 test file covers this file: vendor/bin/pest --tia runs it',
    ])."\n");
});

it('prints nothing for a file the graph does not know', function () {
    app()->instance(Differ::class, new FakeDiffer("+    // a comment\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Concerns/Nothing.php']);

    expect(Artisan::output())->toBe('');
});

it('rebuilds the graph only when the fingerprint of the app has changed', function () {
    app()->instance(Differ::class, new FakeDiffer(''));
    $project = app(Project::class);
    $migration = workbench_path('database/migrations/0001_01_01_000001_create_authors_table.php');
    $originalMtime = filemtime($migration);

    $this->artisan('quine:nudge', ['file' => 'workbench/app/Models/Post.php'])->assertSuccessful();
    $first = Graph::load($project->graphPath)->meta;

    $this->artisan('quine:nudge', ['file' => 'workbench/app/Models/Post.php'])->assertSuccessful();
    $second = Graph::load($project->graphPath)->meta;

    try {
        touch($migration, $originalMtime + 60);
        $this->artisan('quine:nudge', ['file' => 'workbench/app/Models/Post.php'])->assertSuccessful();
        $third = Graph::load($project->graphPath)->meta;
        $expected = Fingerprint::of($project);
    } finally {
        touch($migration, $originalMtime);
    }

    expect($second['fingerprint'])->toBe($first['fingerprint'])
        ->and($second['built_at'])->toBe($first['built_at'])
        ->and($third['fingerprint'])->not->toBe($first['fingerprint'])
        ->and($third['fingerprint'])->toBe($expected);
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
