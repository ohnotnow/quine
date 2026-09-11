<?php

declare(strict_types=1);

use Ohffs\Quine\Recipes\NullableBelongsTo;

return [

    // Every relative path quine prints or receives is relative to this.
    'base_path' => base_path(),

    'graph_path' => storage_path('app/quine/graph.json'),

    'namespace' => 'App\\',

    'paths' => [
        'app' => app_path(),
        'migrations' => database_path('migrations'),
        'views' => null, // null = config('view.paths')
        'tests' => base_path('tests'),
        'fixtures' => [database_path('factories'), database_path('seeders')],
    ],

    'tia_graph' => null, // null = discover ~/.pest/tia/<basename>-*/graph.json

    'reach' => [
        'depth' => 6, // how many member hops the nudge walks from an edited method before giving up
    ],

    'recipes' => [
        NullableBelongsTo::class,
    ],

];
