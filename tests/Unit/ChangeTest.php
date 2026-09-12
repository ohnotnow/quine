<?php

declare(strict_types=1);

use Ohffs\Quine\Change;

it('lists the added lines of a diff without the plus sign or the file header', function () {
    $change = Change::forFile('database/migrations/x.php', "--- a\n+++ b\n-old\n+new line");

    expect($change->addedLines())->toBe(['new line'])
        ->and($change->isFile())->toBeTrue()
        ->and($change->isNode())->toBeFalse();
});

it('lists every line inside the hunks, context included, without headers or signs', function () {
    $change = Change::forFile('app/Models/X.php', "--- a/x\n+++ b/x\n@@ -1,3 +1,3 @@\n kept\n-old\n+new\n");

    expect($change->nearbyLines())->toBe(['kept', 'old', 'new']);
});

it('describes a node being asked about', function () {
    $change = Change::forNode('App\Models\Note');

    expect($change->node)->toBe('App\Models\Note')
        ->and($change->isNode())->toBeTrue()
        ->and($change->addedLines())->toBe([]);
});

it('names the methods whose declaration a hunk header carries, as the edited methods', function () {
    $change = Change::forFile('workbench/app/Models/Post.php', implode("\n", [
        '@@ -33,1 +33,1 @@ public function isPublished(): bool',
        '     {',
        '-        return $this->created_at !== null;',
        '+        return $this->exists;',
        '     }',
    ])."\n");

    expect($change->editedMethods())->toBe(['isPublished'])
        ->and(Change::forFile('workbench/app/Models/Post.php', "@@ -1,1 +1,1 @@\n-a\n+b\n")->editedMethods())->toBe([])
        ->and(Change::forFile('workbench/app/Models/Post.php', "-a\n+b\n")->editedMethods())->toBe([]);
});

it('gives each changed line to the nearest declaration above it, never to one that is only trailing context', function () {
    $change = Change::forFile('app/Models/Service.php', implode("\n", [
        '@@ -20,7 +20,7 @@ public function users(): BelongsToMany',
        '     {',
        '-        return null;',
        '+        return $this->belongsToMany(User::class);',
        '     }',
        ' ',
        '     public function manager(): BelongsTo',
        '     {',
        '@@ -40,3 +40,3 @@',
        '     public function report(): string',
        '-        return "a";',
        '+        return "b";',
    ])."\n");

    expect($change->touchedMethods())->toBe(['users', 'report']);
});
