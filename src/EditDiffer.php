<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Illuminate\Filesystem\Filesystem;

/**
 * The diff of one edit, from the text it replaced and the text it wrote, as
 * the editor hook hands them over. Git would show every uncommitted change to
 * the file; this shows the edit just made, with the same three lines of
 * context git prints, read from the written file, because a recipe judges a
 * change by what sits beside it.
 */
final readonly class EditDiffer implements Differ
{
    private const int CONTEXT = 3;

    public function __construct(
        private Project $project,
        private ?string $old,
        private string $new,
    ) {}

    public function diff(string $relativePath): string
    {
        $added = $this->lines($this->new);

        if ($this->old === null) {
            return implode('', array_map(fn (string $line) => "+$line\n", $added));
        }

        $removed = $this->lines($this->old);
        ['before' => $before, 'after' => $after] = $this->context($relativePath, $added);

        return implode('', [
            ...array_map(fn (string $line) => " $line\n", $before),
            ...array_map(fn (string $line) => "-$line\n", $removed),
            ...array_map(fn (string $line) => "+$line\n", $added),
            ...array_map(fn (string $line) => " $line\n", $after),
        ]);
    }

    /**
     * The lines around the first place the written text sits in the file, or
     * nothing when the file does not hold it.
     *
     * @param  list<string>  $added
     * @return array{before: list<string>, after: list<string>}
     */
    private function context(string $relativePath, array $added): array
    {
        $files = new Filesystem;
        $absolute = $this->project->absolute($relativePath);

        if ($added === [] || ! $files->isFile($absolute)) {
            return ['before' => [], 'after' => []];
        }

        $lines = $this->lines($files->get($absolute));
        $count = count($added);

        foreach (array_keys($lines) as $start) {
            if (array_slice($lines, $start, $count) === $added) {
                return [
                    'before' => array_slice($lines, max(0, $start - self::CONTEXT), min($start, self::CONTEXT)),
                    'after' => array_slice($lines, $start + $count, self::CONTEXT),
                ];
            }
        }

        return ['before' => [], 'after' => []];
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
