<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Ohffs\Quine\Differ;

final class KeyedFakeDiffer implements Differ
{
    public function __construct(private readonly string $diff) {}

    public function diff(string $relativePath): string
    {
        return $this->diff;
    }
}

it('nudges about the cache key built from a column a migration edit makes nullable', function () {
    app()->instance(Differ::class, new KeyedFakeDiffer("+            \$table->string('title')->nullable();\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/database/migrations/0001_01_01_000002_create_posts_table.php']);

    expect(Artisan::output())->toStartWith('Quine: hang on. ')
        ->toContain("\nworkbench/app/Support/PostCache.php:19  builds a cache key from Post->title, which can now be null\n");
});

it('nudges about the cache key built from an accessor a model edit removed, under the name it is read by', function () {
    app()->instance(Differ::class, new KeyedFakeDiffer("-    public function getTitleLabelAttribute(): string\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nworkbench/app/Support/PostCache.php:19  builds a cache key from Post->title_label, which was removed\n");
});

it('says nothing about a column no sink reads', function () {
    app()->instance(Differ::class, new KeyedFakeDiffer("+            \$table->text('body')->nullable();\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/database/migrations/0001_01_01_000002_create_posts_table.php']);

    expect(Artisan::output())->not->toContain('builds a');
});

it('names the sink a nullable member feeds when the model is asked about, and nothing for a NOT NULL one', function () {
    $this->artisan('quine:update')->assertSuccessful();

    Artisan::call('quine:ask', ['node' => 'Comment']);

    expect(Artisan::output())->toContain('workbench/app/Support/PostCache.php:28  builds a storage path from Comment->author, which can be null');

    Artisan::call('quine:ask', ['node' => 'Post']);

    expect(Artisan::output())->not->toContain('builds a cache key');
});

it('nudges about the cache key built from a column whose cast a model edit changed', function () {
    app()->instance(Differ::class, new KeyedFakeDiffer(implode("\n", [
        '     protected function casts(): array',
        '     {',
        '         return [',
        "-            'title' => 'string',",
        "+            'title' => AsStringable::class,",
        '         ];',
    ])."\n"));

    Artisan::call('quine:nudge', ['file' => 'workbench/app/Models/Post.php']);

    expect(Artisan::output())->toContain("\nworkbench/app/Support/PostCache.php:19  builds a cache key from Post->title, which changed cast\n");
});
