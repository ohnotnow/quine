<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Recipes;

use Illuminate\Contracts\Container\Container;
use Ohwhatnow\Quine\Change;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Nudge;

final readonly class Registry
{
    /**
     * @param  list<Recipe>  $recipes
     */
    public function __construct(private array $recipes) {}

    /**
     * The recipes named in config('quine.recipes'), resolved through the container
     * so a host app's own recipe can take constructor dependencies.
     */
    public static function fromConfig(Container $app): self
    {
        $recipes = [];

        foreach (config()->array('quine.recipes') as $class) {
            $recipe = is_string($class) ? $app->make($class) : null;

            if ($recipe instanceof Recipe) {
                $recipes[] = $recipe;
            }
        }

        return new self($recipes);
    }

    /**
     * Every recipe's nudges, in registration order.
     *
     * @return list<Nudge>
     */
    public function nudges(Change $change, Graph $graph): array
    {
        $nudges = [];

        foreach ($this->recipes as $recipe) {
            $nudges = [...$nudges, ...$recipe->nudges($change, $graph)];
        }

        return $nudges;
    }
}
