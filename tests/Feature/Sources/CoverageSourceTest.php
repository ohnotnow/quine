<?php

declare(strict_types=1);

it('maps each covered file to the test files that execute it, using 0-based indices', function () {
    $coverage = updatedGraph()->coverage;

    expect($coverage['workbench/resources/views/posts/show.blade.php'])->toBe(['workbench/tests/Feature/PostPageTest.php'])
        ->and($coverage['workbench/app/Models/Post.php'])->toBe(['workbench/tests/Feature/PostPageTest.php']);
});

it('reports the test files on disk that the tia cache has never seen and calls the cache stale', function () {
    $meta = updatedGraph()->meta;

    expect($meta['coverage'])->toBe('stale')
        ->and($meta['coverage_missing_tests'])->toBe(['workbench/tests/Feature/CommentPageTest.php']);
});

it('says the overlay is unavailable when the configured tia graph does not exist', function () {
    config()->set('quine.tia_graph', dirname(config()->string('quine.graph_path')).'/no-such-tia.json');

    $graph = updatedGraph();

    expect($graph->coverage)->toBe([])
        ->and($graph->meta['coverage'])->toBe('unavailable');
});
