<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Illuminate\Filesystem\Filesystem;
use Ohffs\Quine\Support\Declarations;
use SebastianBergmann\Diff\Differ as LineDiffer;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

/**
 * The diff of a file against the copy quine last saw of it: the change just
 * made, whichever tool made it. With no baseline the whole file is new.
 */
final readonly class SnapshotDiffer implements Differ
{
    public function __construct(
        private Project $project,
        private ?string $baseline,
    ) {}

    public function diff(string $relativePath): string
    {
        $files = new Filesystem;
        $absolute = $this->project->absolute($relativePath);
        $current = $files->isFile($absolute) ? $files->get($absolute) : null;

        if ($this->baseline === null) {
            return $current === null ? '' : implode('', array_map(fn (string $line) => "+$line\n", $this->lines($current)));
        }

        if ($current === null) {
            return implode('', array_map(fn (string $line) => "-$line\n", $this->lines($this->baseline)));
        }

        if ($current === $this->baseline) {
            return '';
        }

        $unified = (new LineDiffer(new StrictUnifiedDiffOutputBuilder(['contextLines' => 3, 'addLineNumbers' => true, 'fromFile' => 'a', 'toFile' => 'b'])))->diff($this->baseline, $current);

        return $this->withDeclarations($this->lines($unified), $this->lines($current));
    }

    /**
     * Each hunk header gains the method declaration nearest above the hunk's
     * first changed line, the way git prints one with a function diff driver.
     * The hunk opens three context lines earlier, so the header's own start
     * line is not the place to look from.
     *
     * @param  list<string>  $diff
     * @param  list<string>  $file
     */
    private function withDeclarations(array $diff, array $file): string
    {
        $out = '';

        foreach ($diff as $i => $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $match) === 1) {
                $index = (int) $match[1] - 1;

                for ($j = $i + 1; isset($diff[$j]) && str_starts_with($diff[$j], ' '); $j++) {
                    $index++;
                }

                $line = rtrim($line.' '.Declarations::above($file, $index));
            }

            $out .= $line."\n";
        }

        return $out;
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
