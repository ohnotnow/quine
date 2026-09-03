<?php

declare(strict_types=1);

use Ohwhatnow\Quine\Change;

it('lists the added lines of a diff without the plus sign or the file header', function () {
    $change = Change::forFile('database/migrations/x.php', "--- a\n+++ b\n-old\n+new line");

    expect($change->addedLines())->toBe(['new line'])
        ->and($change->isFile())->toBeTrue()
        ->and($change->isNode())->toBeFalse();
});

it('describes a node being asked about', function () {
    $change = Change::forNode('App\Models\Note');

    expect($change->node)->toBe('App\Models\Note')
        ->and($change->isNode())->toBeTrue()
        ->and($change->addedLines())->toBe([]);
});
