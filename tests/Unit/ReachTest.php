<?php

declare(strict_types=1);

use Ohffs\Quine\Change;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use Ohffs\Quine\Reach;

it('still knows a class whose only edges are the symbol edges from its own methods', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('Workbench\App\Policies\PostPolicy::view', 'Workbench\App\Models\Post::isPublished', 'calls', 'calls isPublished()', 'workbench/app/Policies/PostPolicy.php:9');
    $graph->coverage['workbench/app/Policies/PostPolicy.php'] = ['workbench/tests/Feature/PostPageTest.php'];

    $digest = (new Reach($graph, app(Project::class)))->digest(Change::forFile('workbench/app/Policies/PostPolicy.php', "+        return \$post->isPublished();\n"));

    expect($digest['notes'])->toContain('1 test file covers this file: PostPageTest.php (vendor/bin/pest --tia runs it)');
});
