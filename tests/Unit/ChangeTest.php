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
