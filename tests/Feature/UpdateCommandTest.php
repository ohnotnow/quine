<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Ohffs\Quine\Graph;
use Workbench\App\Events\PostPublished;
use Workbench\App\Listeners\NotifyEditors;

it('writes the graph to the configured graph path', function () {
    $path = config()->string('quine.graph_path');

    $this->artisan('quine:update')->assertSuccessful();

    $graph = json_decode(file_get_contents($path), true);

    expect(array_keys($graph))->toBe(['meta', 'schema', 'models', 'edges', 'coverage'])
        ->and($graph['meta']['built_at'])->toBeString();
});

it('writes the graph to the --json path instead', function () {
    $path = dirname(config()->string('quine.graph_path')).'/elsewhere.json';

    $this->artisan('quine:update', ['--json' => $path])
        ->expectsOutputToContain(' edges written to '.$path)
        ->assertSuccessful();

    expect($path)->toBeFile()
        ->and(config()->string('quine.graph_path'))->not->toBeFile();
});

it('ends with a human summary of models and nudges', function () {
    $this->artisan('quine:update')
        ->expectsOutputToContain('MODELS')
        ->expectsOutputToContain('author(): BelongsTo -> Author  NULLABLE comments.author_id')
        ->expectsOutputToContain('tags(): BelongsToMany -> Tag  via post_tag')
        ->expectsOutputToContain('on created -> closure workbench/app/Models/Post.php:28')
        ->expectsOutputToContain('accessors: excerpt')
        ->expectsOutputToContain('casts: id=int')
        ->expectsOutputToContain('CHECK BEFORE EDITING')
        ->expectsOutputToContain('Comment->author can be null')
        ->assertSuccessful();
});

it('lists the hidden edges: events, gates, policies and the schedule', function () {
    $this->artisan('quine:update')
        ->expectsOutputToContain('RUNS WITHOUT BEING CALLED')
        ->expectsOutputToContain(PostPublished::class.' --queued listener--> '.NotifyEditors::class.'@handle')
        ->expectsOutputToContain('schedule --0 0 * * *--> inspire')
        ->assertSuccessful();
});

it('reports the coverage state and how many test files render each page', function () {
    $this->artisan('quine:update')
        ->expectsOutputToContain('ROUTES')
        ->expectsOutputToContain('coverage data predates 1 test file (CommentPageTest.php)')
        ->expectsOutputToContain('workbench/resources/views/posts/show.blade.php  (1 test files)')
        ->assertSuccessful();
});

it('prints nothing when quiet but still writes the graph', function () {
    Artisan::call('quine:update', ['--quiet' => true]);

    expect(Artisan::output())->toBe('')
        ->and(config()->string('quine.graph_path'))->toBeFile();
});

it('says the tia cache is fresh when every test on disk is in it', function () {
    $tests = dirname(config()->string('quine.graph_path')).'/tests';
    mkdir($tests, 0755, true);
    config()->set('quine.paths.tests', $tests);

    $this->artisan('quine:update')
        ->expectsOutputToContain('tia cache is fresh: 1 test files')
        ->assertSuccessful();
});

it('prints how many member consumers the symbol index holds', function () {
    $this->withTemplates();

    $this->artisan('quine:update')
        ->expectsOutputToContain('symbols: 15 calls, 35 fetches from 23 files; templates: 3 indexed (1 without composer data), 1 quine could not read (quine:update -v says which)')
        ->assertSuccessful();
});

it('names each template bladestan failed to compile, with the error, when asked for verbose output', function () {
    $this->withTemplates();

    $this->artisan('quine:update', ['-v' => true])
        ->expectsOutputToContain('could not read workbench/resources/views/posts/broken.blade.php: View [posts/broken.blade.php] contains syntx errors.')
        ->expectsOutputToContain("read workbench/resources/views/posts/composed.blade.php without its view composers' data: ")
        ->assertSuccessful();
});

it('keeps the typed reads of a template whose view composer it could not call', function () {
    $this->withTemplates();

    expect(updatedGraph()->edgesFrom('workbench/resources/views/posts/composed.blade.php', 'fetches'))->toContain([
        'from' => 'workbench/resources/views/posts/composed.blade.php',
        'to' => 'Workbench\App\Models\Post::title',
        'kind' => 'fetches',
        'label' => 'fetches title',
        'at' => 'workbench/resources/views/posts/composed.blade.php:2',
    ]);
});

it('still indexes PHP members and says so when bladestan is not installed', function () {
    // The TestCase already binds a not-installed Bladestan; this test is the one that relies on it.
    $this->artisan('quine:update')
        ->expectsOutputToContain('templates: not indexed (tomasvotruba/bladestan is not installed)')
        ->assertSuccessful();

    $graph = Graph::load(config()->string('quine.graph_path'));

    expect($graph->edgesTo('Workbench\App\Models\Post::isPublished'))->not->toBeEmpty()
        ->and($graph->edgesFrom('workbench/resources/views/posts/show.blade.php', 'fetches'))->toBe([]);
});

it('clears the background rebuild lock when it finishes', function () {
    $lock = dirname(config()->string('quine.graph_path')).'/update.lock';
    File::ensureDirectoryExists(dirname($lock));
    File::put($lock, '{"pid":0,"started":0}');

    $this->artisan('quine:update', ['--quiet' => true])->assertSuccessful();

    expect(File::exists($lock))->toBeFalse();
});

it('records how long each source took, and the total, in the graph meta', function () {
    $this->artisan('quine:update', ['--quiet' => true])->assertSuccessful();

    $meta = Graph::load(config()->string('quine.graph_path'))->meta;

    expect($meta['timings'])->toHaveCount(12)
        ->and($meta['timings'])->toHaveKeys(['SchemaSource', 'ModelsSource', 'SymbolsSource', 'BladeSource', 'CoverageSource'])
        ->and($meta['built_in'])->toBeFloat();
});

it('prints the per-source timings slowest first when asked for verbose output', function () {
    $this->artisan('quine:update', ['-v' => true])
        ->expectsOutputToContain('SymbolsSource')
        ->expectsOutputToContain('total')
        ->assertSuccessful();
});
