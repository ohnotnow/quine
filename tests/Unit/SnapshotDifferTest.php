<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Ohffs\Quine\Change;
use Ohffs\Quine\Project;
use Ohffs\Quine\SnapshotDiffer;

beforeEach(function () {
    $this->project = app(Project::class);
    $this->path = 'workbench/app/Models/SnapshotScratch.php';
    $this->lines = [
        '<?php',
        '',
        'use App\Models\Author;',
        '',
        'class SnapshotScratch',
        '{',
        '    public function author()',
        '    {',
        '        return $this->belongsTo(Author::class);',
        '    }',
        '',
        '    public function tags()',
        '    {',
        '        return $this->belongsToMany(Tag::class);',
        '    }',
        '}',
    ];
    $this->baseline = implode("\n", $this->lines)."\n";
    File::put($this->project->absolute($this->path), $this->baseline);
});

afterEach(function () {
    File::delete($this->project->absolute($this->path));
});

it('treats a file with no baseline as every line added', function () {
    $diff = (new SnapshotDiffer($this->project, null))->diff($this->path);

    expect($diff)->toBe(implode('', array_map(fn (string $line) => "+$line\n", $this->lines)))
        ->and(Change::forFile($this->path, $diff)->addedLines())->toBe($this->lines);
});

it('names the enclosing method in the hunk header for a change inside a method body', function () {
    File::put($this->project->absolute($this->path), str_replace('belongsTo(Author::class);', 'belongsTo(Author::class)->withDefault();', $this->baseline));

    $diff = (new SnapshotDiffer($this->project, $this->baseline))->diff($this->path);
    $change = Change::forFile($this->path, $diff);

    expect(preg_match_all('/^@@ /m', $diff))->toBe(1)
        ->and($diff)->toContain('@@ public function author()')
        ->and($change->touchedMethods())->toBe(['author'])
        ->and($change->removedLines())->toBe(['        return $this->belongsTo(Author::class);'])
        ->and($change->addedLines())->toBe(['        return $this->belongsTo(Author::class)->withDefault();']);
});

it('attributes two changes to their own methods when the context merges them into one hunk', function () {
    $edited = str_replace(
        ['belongsTo(Author::class);', 'belongsToMany(Tag::class);'],
        ['belongsTo(Author::class)->withDefault();', 'belongsToMany(Tag::class)->withTimestamps();'],
        $this->baseline,
    );
    File::put($this->project->absolute($this->path), $edited);

    $diff = (new SnapshotDiffer($this->project, $this->baseline))->diff($this->path);

    expect(preg_match_all('/^@@ /m', $diff))->toBe(1)
        ->and(Change::forFile($this->path, $diff)->touchedMethods())->toBe(['author', 'tags']);
});

it('leaves the header bare for a change above any method', function () {
    File::put($this->project->absolute($this->path), str_replace("use App\\Models\\Author;\n", "use App\\Models\\Author;\nuse App\\Models\\Tag;\n", $this->baseline));

    $diff = (new SnapshotDiffer($this->project, $this->baseline))->diff($this->path);
    $change = Change::forFile($this->path, $diff);

    expect($diff)->toMatch('/^@@ -\d+,\d+ \+\d+,\d+ @@$/m')
        ->and($change->touchedMethods())->toBe([])
        ->and($change->addedLines())->toBe(['use App\Models\Tag;']);
});

it('is empty when the file matches its baseline', function () {
    expect((new SnapshotDiffer($this->project, $this->baseline))->diff($this->path))->toBe('');
});

it('shows a deleted file as every baseline line removed', function () {
    File::delete($this->project->absolute($this->path));

    $diff = (new SnapshotDiffer($this->project, $this->baseline))->diff($this->path);

    expect($diff)->toBe(implode('', array_map(fn (string $line) => "-$line\n", $this->lines)))
        ->and(Change::forFile($this->path, $diff)->removedLines())->toBe($this->lines);
});
