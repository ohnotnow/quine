<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Workbench\App\Http\Controllers\PostController;

it('records the controller call site of a model method as a calls edge', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Models\Post::isPublished'))->toContain([
        'from' => PostController::class,
        'to' => 'Workbench\App\Models\Post::isPublished',
        'kind' => 'calls',
        'label' => 'calls isPublished()',
        'at' => 'workbench/app/Http/Controllers/PostController.php:12',
    ]);
});

it('records a call site in a test file under the test path as a node', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Models\Post::isPublished'))->toContain([
        'from' => 'workbench/tests/Feature/PostPageTest.php',
        'to' => 'Workbench\App\Models\Post::isPublished',
        'kind' => 'calls',
        'label' => 'calls isPublished()',
        'at' => 'workbench/tests/Feature/PostPageTest.php:14',
    ]);
});

it('records constructing an app class as a calls edge to its constructor', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Mail\PostAnnounced::__construct'))->toContain([
        'from' => 'Workbench\App\Models\Post',
        'to' => 'Workbench\App\Mail\PostAnnounced::__construct',
        'kind' => 'calls',
        'label' => 'new PostAnnounced(...)',
        'at' => 'workbench/app/Models/Post.php:56',
    ]);
});

it('records a static call against the class it is called on', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Models\Post::factory'))->toContain([
        'from' => 'workbench/tests/Feature/PostPageTest.php',
        'to' => 'Workbench\App\Models\Post::factory',
        'kind' => 'calls',
        'label' => 'calls static factory()',
        'at' => 'workbench/tests/Feature/PostPageTest.php:14',
    ]);
});

it('records a property fetch, naming the guard only when the receiver can be null', function () {
    $graph = updatedGraph();

    expect($graph->edgesTo('Workbench\App\Models\Post::title'))->toContain([
        'from' => PostController::class,
        'to' => 'Workbench\App\Models\Post::title',
        'kind' => 'fetches',
        'label' => 'fetches title',
        'at' => 'workbench/app/Http/Controllers/PostController.php:12',
    ]);

    $fromTheTest = array_filter($graph->edgesTo('Workbench\App\Models\Author::name'), fn (array $edge) => $edge['from'] === 'workbench/tests/Feature/PostPageTest.php');

    expect(array_column($fromTheTest, 'label', 'at'))->toBe([
        'workbench/tests/Feature/PostPageTest.php:20' => 'fetches name (null-safe)',
        'workbench/tests/Feature/PostPageTest.php:21' => 'fetches name (unguarded)',
    ]);
});

it('never records a member outside the app namespace, and never a class consuming itself', function () {
    $graph = updatedGraph();
    $symbols = array_filter($graph->edges, fn (array $edge) => in_array($edge['kind'], ['calls', 'fetches'], true));

    expect($symbols)->not->toBeEmpty();

    foreach ($symbols as $edge) {
        expect($edge['to'])->toStartWith('Workbench\App\\')
            ->and(Str::beforeLast($edge['to'], '::'))->not->toBe($edge['from']);
    }
});

it('records what a template reads, on the blade line, through the view call that types it', function () {
    $this->withTemplates();

    $edges = updatedGraph()->edgesTo('Workbench\App\Models\Comment::author');

    foreach ([4, 5] as $line) {
        expect($edges)->toContain([
            'from' => 'workbench/resources/views/posts/comments.blade.php',
            'to' => 'Workbench\App\Models\Comment::author',
            'kind' => 'fetches',
            'label' => 'fetches author',
            'at' => "workbench/resources/views/posts/comments.blade.php:$line",
        ]);
    }
});

it('labels a template read of a nullable relation by whether it is null-safe', function () {
    $this->withTemplates();

    $fromTemplate = array_filter(updatedGraph()->edgesTo('Workbench\App\Models\Author::name'), fn (array $edge) => $edge['from'] === 'workbench/resources/views/posts/comments.blade.php');

    expect(array_column($fromTemplate, 'label', 'at'))->toBe([
        'workbench/resources/views/posts/comments.blade.php:4' => 'fetches name (unguarded)',
        'workbench/resources/views/posts/comments.blade.php:5' => 'fetches name (null-safe)',
    ]);
});

it('reaches a template through the view call that renders it, and records nothing for a gate check', function () {
    $this->withTemplates();

    $graph = updatedGraph();

    expect($graph->edgesTo('Workbench\App\Models\Post::title'))->toContain([
        'from' => 'workbench/resources/views/posts/show.blade.php',
        'to' => 'Workbench\App\Models\Post::title',
        'kind' => 'fetches',
        'label' => 'fetches title',
        'at' => 'workbench/resources/views/posts/show.blade.php:2',
    ])->and(array_filter($graph->edgesFrom('workbench/resources/views/posts/show.blade.php'), fn (array $edge) => in_array($edge['kind'], ['calls', 'fetches'], true) && str_contains($edge['to'], 'Gate')))->toBe([]);
});
