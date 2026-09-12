<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Ohffs\Quine\Recipes\Registry;

/**
 * What quine has to say about one change: the reach digest and the recipe
 * nudges, under an opener that says how much to worry. One seam for both
 * doors of quine:nudge, and the one the session tests fake.
 */
class Nudger
{
    public function __construct(
        private readonly Project $project,
        private readonly Registry $recipes,
    ) {}

    /**
     * The lines to print, opener first, or nothing when there is nothing to say.
     *
     * @return list<string>
     */
    public function lines(Change $change, Graph $graph): array
    {
        ['callers' => $callers, 'reach' => $reach, 'gaps' => $gaps, 'hidden' => $hidden, 'notes' => $notes] = (new Reach($graph, $this->project))->digest($change);
        $nudges = array_map('strval', $this->recipes->nudges($change, $graph));

        if ($callers === [] && $reach === [] && $gaps === [] && $hidden === [] && $notes === [] && $nudges === []) {
            return [];
        }

        // One opener, no verdict word: two cold readers said "hang on" and "fyi" told them nothing. Each line carries its own weight.
        $opener = "Quine, after your edit to {$change->path}:";

        return [$opener, ...$callers, ...$reach, ...$gaps, ...$hidden, ...$notes, ...$nudges];
    }
}
