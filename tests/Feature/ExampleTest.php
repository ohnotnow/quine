<?php

declare(strict_types=1);

use Ohwhatnow\Quine\Quine;

it('resolves the singleton', function () {
    expect(app(Quine::class))->toBeInstanceOf(Quine::class);
});

it('returns the same instance from the container', function () {
    expect(app(Quine::class))->toBe(app(Quine::class));
});

it('merges the package config', function () {
    expect(config('quine.placeholder'))->toBe('default');
});

it('registers the artisan command', function () {
    $this->artisan('quine:placeholder')
        ->expectsOutputToContain('Quine placeholder command executed.')
        ->assertSuccessful();
});
