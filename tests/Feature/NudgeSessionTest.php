<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Ohffs\Quine\Change;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Nudger;
use Ohffs\Quine\Project;
use Ohffs\Quine\Rebuild;

/**
 * Records every Change the command hands it and answers with one line per file.
 */
final class FakeNudger extends Nudger
{
    /** @var list<Change> */
    public array $changes = [];

    public function __construct() {}

    public function lines(Change $change, Graph $graph): array
    {
        $this->changes[] = $change;

        return ["Quine, after your edit to {$change->path}:"];
    }
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/quine-session-'.uniqid();
    foreach (['app/Models', 'database/migrations', 'resources/views', 'routes', 'tests'] as $dir) {
        File::ensureDirectoryExists("$this->root/$dir");
    }
    config()->set('quine.base_path', $this->root);
    config()->set('quine.namespace', 'App\\');
    config()->set('quine.paths.app', "$this->root/app");
    config()->set('quine.paths.migrations', "$this->root/database/migrations");
    config()->set('quine.paths.views', ["$this->root/resources/views"]);
    config()->set('quine.paths.tests', "$this->root/tests");
    config()->set('quine.graph_path', "$this->root/storage/quine/graph.json");
    File::ensureDirectoryExists("$this->root/storage/quine");
    (new Graph)->save("$this->root/storage/quine/graph.json");

    $this->nudger = new FakeNudger;
    app()->instance(Nudger::class, $this->nudger);
    $this->rebuilds = 0;
    app()->instance(Rebuild::class, new Rebuild(app(Project::class), function (): int {
        $this->rebuilds++;

        return 1;
    }));
    $this->marker = "$this->root/storage/quine/sessions/sess-1/marker";
    $this->write = function (string $relative, string $content): void {
        File::put("$this->root/$relative", $content);
        touch("$this->root/$relative", time());
    };
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('prints nothing and leaves a marker on the first run of a session', function () {
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);

    expect(Artisan::output())->toBe('')
        ->and(File::isFile($this->marker))->toBeTrue()
        ->and($this->nudger->changes)->toBe([]);
});

it('nudges a file written since the marker, with the whole file as the change when it is new', function () {
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);
    ($this->write)('app/Models/Thing.php', "<?php\n\nclass Thing\n{\n}\n");

    Artisan::call('quine:nudge', ['--session' => 'sess-1']);

    expect(Artisan::output())->toBe("Quine, after your edit to app/Models/Thing.php:\n")
        ->and($this->rebuilds)->toBe(1)
        ->and($this->nudger->changes)->toHaveCount(1)
        ->and($this->nudger->changes[0]->path)->toBe('app/Models/Thing.php')
        ->and($this->nudger->changes[0]->addedLines())->toBe(['<?php', '', 'class Thing', '{', '}']);
});

it('says nothing when a touched file matches its snapshot', function () {
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);
    ($this->write)('app/Models/Thing.php', "<?php\n");
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);
    ($this->write)('app/Models/Thing.php', "<?php\n");

    Artisan::call('quine:nudge', ['--session' => 'sess-1']);

    expect(Artisan::output())->toBe('')
        ->and($this->nudger->changes)->toHaveCount(1);
});

it('diffs a second change against the snapshot, not against the first', function () {
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);
    ($this->write)('app/Models/Thing.php', "<?php\n\nclass Thing\n{\n}\n");
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);
    ($this->write)('app/Models/Thing.php', "<?php\n\nclass Thing\n{\n    public int \$n = 1;\n}\n");

    Artisan::call('quine:nudge', ['--session' => 'sess-1']);

    expect($this->nudger->changes)->toHaveCount(2)
        ->and($this->nudger->changes[1]->addedLines())->toBe(['    public int $n = 1;'])
        ->and($this->nudger->changes[1]->removedLines())->toBe([]);
});

it('prints one block per changed file in path order, a blank line between, starts one rebuild, and ignores unwatched files', function () {
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);
    ($this->write)('routes/web.php', "<?php\n");
    ($this->write)('app/Models/Thing.php', "<?php\n");
    ($this->write)('resources/views/thing.blade.php', "<div></div>\n");
    ($this->write)('tests/ThingTest.php', "<?php\n");
    File::put("$this->root/README.md", "# hi\n");

    Artisan::call('quine:nudge', ['--session' => 'sess-1']);

    expect(Artisan::output())->toBe("Quine, after your edit to app/Models/Thing.php:\n\nQuine, after your edit to resources/views/thing.blade.php:\n\nQuine, after your edit to routes/web.php:\n")
        ->and($this->rebuilds)->toBe(1);
});

it('refuses --session together with --edit without failing the edit', function () {
    $exit = Artisan::call('quine:nudge', ['--session' => 'sess-1', '--edit' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('quine: --session and --edit are different doors')
        ->and(File::isFile($this->marker))->toBeFalse();
});

it('prints a usage hint and succeeds when given neither a file nor a session', function () {
    $exit = Artisan::call('quine:nudge');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('quine:nudge <file> [--edit], or quine:nudge --session=<id>');
});

it('starts no rebuild when nothing changed', function () {
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);

    expect($this->rebuilds)->toBe(0);
});

it('starts a rebuild for a stale fingerprint even when the graph knows every changed file', function () {
    $graph = new Graph;
    $graph->edge('App\\Models\\Thing', 'App\\Models\\Other', 'uses', 'uses', null);
    $graph->save("$this->root/storage/quine/graph.json");
    Artisan::call('quine:nudge', ['--session' => 'sess-1']);
    ($this->write)('app/Models/Thing.php', "<?php\n");

    Artisan::call('quine:nudge', ['--session' => 'sess-1']);

    expect(Artisan::output())->toBe("Quine, after your edit to app/Models/Thing.php:\n")
        ->and($this->rebuilds)->toBe(1);
});
