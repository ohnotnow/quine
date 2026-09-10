<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Stringable;

/**
 * What a recipe hands back: where to look and, in one sentence, why.
 */
final readonly class Nudge implements Stringable
{
    public function __construct(
        public string $file,
        public ?int $line,
        public string $reason,
    ) {}

    public function __toString(): string
    {
        return $this->file.($this->line === null ? '' : ':'.$this->line).'  '.$this->reason;
    }
}
