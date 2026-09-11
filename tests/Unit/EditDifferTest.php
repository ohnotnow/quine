<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Ohffs\Quine\EditDiffer;
use Ohffs\Quine\Project;

beforeEach(function () {
    $this->project = app(Project::class);
    $this->path = 'workbench/app/Models/Scratch.php';
    File::put($this->project->absolute($this->path), implode("\n", [
        '<?php',
        '',
        'class Scratch',
        '{',
        '    public function author()',
        '    {',
        '        return $this->belongsTo(Author::class)->withDefault();',
        '    }',
        '',
        '    public function tags()',
        '    {',
        '        return $this->belongsToMany(Tag::class);',
        '    }',
        '}',
    ])."\n");
});

afterEach(function () {
    File::delete($this->project->absolute($this->path));
});

it('diffs an edit as removed and added lines with three lines of context from the written file', function () {
    $differ = new EditDiffer($this->project, "        return \$this->belongsTo(Author::class);\n", "        return \$this->belongsTo(Author::class)->withDefault();\n");

    expect($differ->diff($this->path))->toBe(implode("\n", [
        ' {',
        '     public function author()',
        '     {',
        '-        return $this->belongsTo(Author::class);',
        '+        return $this->belongsTo(Author::class)->withDefault();',
        '     }',
        ' ',
        '     public function tags()',
    ])."\n");
});

it('treats a whole-file write as every line added', function () {
    $differ = new EditDiffer($this->project, null, "<?php\n\nreturn 1;\n");

    expect($differ->diff($this->path))->toBe("+<?php\n+\n+return 1;\n");
});

it('gives the bare edit when the new text is not in the file', function () {
    $differ = new EditDiffer($this->project, "old line\n", "new line\n");

    expect($differ->diff($this->path))->toBe("-old line\n+new line\n");
});

it('shows the lines old and new share as context, not as removed and added', function () {
    $old = "    public function author()\n    {\n";
    $new = "    /**\n     * @return BelongsTo<Author, \$this>\n     */\n    public function author()\n    {\n";
    $absolute = $this->project->absolute($this->path);
    File::put($absolute, str_replace($old, $new, File::get($absolute)));
    $differ = new EditDiffer($this->project, $old, $new);

    expect($differ->diff($this->path))->toBe(implode("\n", [
        ' ',
        ' class Scratch',
        ' {',
        '+    /**',
        '+     * @return BelongsTo<Author, $this>',
        '+     */',
        '     public function author()',
        '     {',
        '         return $this->belongsTo(Author::class)->withDefault();',
    ])."\n");
});
