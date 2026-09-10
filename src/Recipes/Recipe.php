<?php

declare(strict_types=1);

namespace Ohffs\Quine\Recipes;

use Ohffs\Quine\Change;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Nudge;

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
