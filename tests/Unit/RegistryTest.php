<?php

declare(strict_types=1);

use Ohwhatnow\Quine\Change;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Nudge;
use Ohwhatnow\Quine\Recipes\Recipe;
use Ohwhatnow\Quine\Recipes\Registry;

final class RecordingRecipe implements Recipe
{
    public static ?Change $change = null;

    public static ?Graph $graph = null;

    public function nudges(Change $change, Graph $graph): array
    {
        self::$change = $change;
        self::$graph = $graph;

        return [new Nudge('first.php', 1, 'first'), new Nudge('second.php', 2, 'second')];
    }
}

it('resolves the configured recipes and hands each the change and graph', function () {
    config()->set('quine.recipes', [RecordingRecipe::class]);

    $change = Change::forNode('App\Models\Note');
    $graph = new Graph;

    $nudges = Registry::fromConfig(app())->nudges($change, $graph);

    expect(array_map('strval', $nudges))->toBe(['first.php:1  first', 'second.php:2  second'])
        ->and(RecordingRecipe::$change)->toBe($change)
        ->and(RecordingRecipe::$graph)->toBe($graph);
});
