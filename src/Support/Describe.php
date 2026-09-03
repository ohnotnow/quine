<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Support;

use Closure;
use Ohwhatnow\Quine\Project;
use ReflectionFunction;

final class Describe
{
    /**
     * A listener, gate or observer target as one readable node name.
     *
     * A closure becomes "closure <relative file>:<line>", an array becomes
     * "Class@method", an object becomes its class, and a string stays as it is.
     */
    public static function callable(mixed $callable, Project $project): string
    {
        if ($callable instanceof Closure) {
            $reflection = new ReflectionFunction($callable);
            $file = $reflection->getFileName();

            return 'closure '.$project->relative($file === false ? '' : $file).':'.$reflection->getStartLine();
        }

        if (is_array($callable)) {
            return implode('@', array_map(fn (mixed $part) => is_object($part) ? $part::class : (is_scalar($part) ? (string) $part : ''), $callable));
        }

        if (is_object($callable)) {
            return $callable::class;
        }

        return is_scalar($callable) ? (string) $callable : '';
    }
}
