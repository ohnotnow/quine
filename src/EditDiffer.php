<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Illuminate\Filesystem\Filesystem;

/**
 * The diff of one edit, from the text it replaced and the text it wrote, as
 * the editor hook hands them over. Git would show every uncommitted change to
 * the file; this shows the edit just made, with the same three lines of
 * context git prints, read from the written file, because a recipe judges a
 * change by what sits beside it. Lines the old and new text share at either
 * end are anchors the editor needed, not changes: they are context too.
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
        $new = $this->lines($this->new);

        if ($this->old === null) {
            return implode('', array_map(fn (string $line) => "+$line\n", $new));
        }

        $old = $this->lines($this->old);
        $prefix = $this->sharedPrefix($old, $new);
        $suffix = $this->sharedSuffix($old, $new, $prefix);
        $removed = array_slice($old, $prefix, count($old) - $prefix - $suffix);
        $added = array_slice($new, $prefix, count($new) - $prefix - $suffix);
        ['before' => $before, 'after' => $after] = $this->context($relativePath, $new, $prefix, $suffix);

        return implode('', [
            ...array_map(fn (string $line) => " $line\n", $before),
            ...array_map(fn (string $line) => "-$line\n", $removed),
            ...array_map(fn (string $line) => "+$line\n", $added),
            ...array_map(fn (string $line) => " $line\n", $after),
        ]);
    }

    /**
     * The lines around the changed part of the written text: from the file
     * when it holds the written text, else the shared lines of the edit itself.
     *
     * @param  list<string>  $new
     * @return array{before: list<string>, after: list<string>}
     */
    private function context(string $relativePath, array $new, int $prefix, int $suffix): array
    {
        $files = new Filesystem;
        $absolute = $this->project->absolute($relativePath);
        $count = count($new);
        $lines = $count > 0 && $files->isFile($absolute) ? $this->lines($files->get($absolute)) : [];

        foreach (array_keys($lines) as $start) {
            if (array_slice($lines, $start, $count) === $new) {
                $from = $start + $prefix;
                $to = $start + $count - $suffix;

                return [
                    'before' => array_slice($lines, max(0, $from - self::CONTEXT), min($from, self::CONTEXT)),
                    'after' => array_slice($lines, $to, self::CONTEXT),
                ];
            }
        }

        return [
            'before' => array_slice($new, max(0, $prefix - self::CONTEXT), min($prefix, self::CONTEXT)),
            'after' => array_slice($new, $count - $suffix, self::CONTEXT),
        ];
    }

    /**
     * How many leading lines the old and new text share.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     */
    private function sharedPrefix(array $old, array $new): int
    {
        $shared = 0;

        while ($shared < count($old) && $shared < count($new) && $old[$shared] === $new[$shared]) {
            $shared++;
        }

        return $shared;
    }

    /**
     * How many trailing lines the old and new text share, beyond the prefix.
     *
     * @param  list<string>  $old
     * @param  list<string>  $new
     */
    private function sharedSuffix(array $old, array $new, int $prefix): int
    {
        $shared = 0;
        $limit = min(count($old), count($new)) - $prefix;

        while ($shared < $limit && $old[count($old) - 1 - $shared] === $new[count($new) - 1 - $shared]) {
            $shared++;
        }

        return $shared;
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
