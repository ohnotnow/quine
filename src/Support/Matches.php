<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Support;

final class Matches
{
    /**
     * Every match of a pattern in a source, as [first capture, line number, second capture or null].
     *
     * @return list<array{0: string, 1: int, 2: ?string}>
     */
    public static function in(string $pattern, string $source): array
    {
        preg_match_all($pattern, $source, $sets, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(fn (array $set) => [
            $set[1][0],
            substr_count($source, "\n", 0, $set[1][1]) + 1,
            $set[2][0] ?? null,
        ], $sets);
    }
}
