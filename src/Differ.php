<?php

declare(strict_types=1);

namespace Ohffs\Quine;

interface Differ
{
    /**
     * The unified diff of a file relative to the project's base path, or the
     * whole file as added lines when there is nothing to diff against.
     */
    public function diff(string $relativePath): string;
}
