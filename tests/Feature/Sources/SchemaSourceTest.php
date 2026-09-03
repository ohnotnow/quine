<?php

declare(strict_types=1);

it('reads foreign key nullability from the migrations', function () {
    $schema = updatedGraph()->schema;

    expect($schema['comments']['author_id']['nullable'])->toBeTrue()
        ->and($schema['posts']['author_id']['nullable'])->toBeFalse();
});

it('reads column types and pivot table columns', function () {
    $schema = updatedGraph()->schema;

    expect($schema['comments']['body']['type'])->toBe('string')
        ->and($schema['post_tag']['post_id']['nullable'])->toBeFalse()
        ->and($schema['post_tag']['tag_id']['nullable'])->toBeFalse();
});

it('leaves the schema empty when the migrations directory is empty', function () {
    $empty = dirname(config()->string('quine.graph_path')).'/no-migrations';
    mkdir($empty, 0755, true);
    config()->set('quine.paths.migrations', $empty);

    expect(updatedGraph()->schema)->toBe([]);
});

it('can build the schema twice in one process', function () {
    updatedGraph();

    expect(updatedGraph()->schema['comments']['author_id']['nullable'])->toBeTrue();
});
