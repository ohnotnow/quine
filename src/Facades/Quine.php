<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Ohwhatnow\Quine\Quine
 */
class Quine extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Ohwhatnow\Quine\Quine::class;
    }
}
