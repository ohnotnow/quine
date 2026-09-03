<?php

declare(strict_types=1);

use Ohwhatnow\Quine\Nudge;

it('renders as file, line and reason', function () {
    expect((string) new Nudge('a.php', 12, 'why'))->toBe('a.php:12  why');
});

it('renders without a line when there is none', function () {
    expect((string) new Nudge('a.php', null, 'why'))->toBe('a.php  why');
});
