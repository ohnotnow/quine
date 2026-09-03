<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Ohwhatnow\Quine\Differ;
use Ohwhatnow\Quine\Fingerprint;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Project;

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
        ->expectsOutputToContain('create_comments_table.php:13  Comment->author can be null')
        ->expectsOutputToContain('comments.blade.php:4  reads $comment->author->name without null-safety')
        ->expectsOutputToContain('no factory, seeder or test ever creates a Comment with a null author')
        ->assertSuccessful();
});

it('prints nothing for an unrelated edit', function () {
    app()->instance(Differ::class, new FakeDiffer("+        return view('posts.show', ['post' => \$post]);\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Http/Controllers/PostController.php']);

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
