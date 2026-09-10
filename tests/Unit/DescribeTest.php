<?php

declare(strict_types=1);

use Ohffs\Quine\Support\Describe;

it('puts the right indefinite article before a class name', function (string $name, string $expected) {
    expect(Describe::withArticle($name))->toBe($expected);
})->with([
    ['Comment', 'a Comment'],
    ['Activity', 'an Activity'],
    ['Author', 'an Author'],
    ['User', 'a User'],
]);
