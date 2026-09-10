<?php

declare(strict_types=1);

namespace Ohffs\Quine\Support;

use Closure;
use Ohffs\Quine\Project;
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

    /**
     * "an Activity", "a Comment": by first letter, with the one common
     * exception of a "U" said as "you".
     */
    public static function withArticle(string $name): string
    {
        $first = strtolower(substr($name, 0, 1));
        $article = in_array($first, ['a', 'e', 'i', 'o'], true) ? 'an' : 'a';

        return "$article $name";
    }
}
