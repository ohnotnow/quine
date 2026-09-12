<?php

declare(strict_types=1);

namespace Ohffs\Quine\Support;

use Ohffs\Quine\Change;

/**
 * Which method a line of a PHP file sits in, from the text alone: the differs
 * write it into their hunk headers so a Change knows the member an edit touched.
 */
final class Declarations
{
    /**
     * The nearest method declaration at or above the index, trimmed, or nothing.
     *
     * @param  list<string>  $lines
     */
    public static function above(array $lines, int $index): string
    {
        for ($i = min($index, count($lines) - 1); $i >= 0; $i--) {
            if (preg_match(Change::DECLARATION, $lines[$i]) === 1) {
                return trim($lines[$i]);
            }
        }

        return '';
    }
}
