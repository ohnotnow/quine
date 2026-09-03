<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Recipes;

use Ohwhatnow\Quine\Change;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Nudge;

interface Recipe
{
    /**
     * The nudges this recipe has for the change, given the graph. Interest is
     * the recipe's own decision: not interested means an empty array.
     *
     * @return list<Nudge>
     */
    public function nudges(Change $change, Graph $graph): array;
}
