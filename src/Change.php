<?php

declare(strict_types=1);

namespace Ohffs\Quine;

/**
 * What is being edited (a file and its diff) or asked about (a graph node).
 * Every path is relative to the project's base path.
 */
final readonly class Change
{
    private function __construct(
        public ?string $path,
        public ?string $diff,
        public ?string $node,
    ) {}

    public static function forFile(string $path, string $diff): self
    {
        return new self($path, $diff, null);
    }

    public static function forNode(string $node): self
    {
        return new self(null, null, $node);
    }

    public function isFile(): bool
    {
        return $this->path !== null;
    }

    public function isNode(): bool
    {
        return $this->node !== null;
    }

    /**
     * The lines the diff adds, without their leading plus sign.
     *
     * @return list<string>
     */
    public function addedLines(): array
    {
        return $this->linesMarked('+');
    }

    /**
     * Every line inside the diff's hunks, changed or context, without its
     * leading sign. A change is judged by what it sits beside: an edit in the
     * body of a relation method has that method's declaration within the
     * three context lines git prints.
     *
     * @return list<string>
     */
    public function nearbyLines(): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $this->diff ?? '') ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '@@') || str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }

            if (in_array($line[0], [' ', '+', '-'], true)) {
                $lines[] = substr($line, 1);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function linesMarked(string $sign): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $this->diff ?? '') ?: [] as $line) {
            if (str_starts_with($line, $sign) && ! str_starts_with($line, $sign.$sign.$sign)) {
                $lines[] = substr($line, 1);
            }
        }

        return $lines;
    }
}
