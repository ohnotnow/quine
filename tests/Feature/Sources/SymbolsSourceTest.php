<?php

declare(strict_types=1);

use Workbench\App\Http\Controllers\PostController;

it('records the controller call site of a model method as a calls edge from the enclosing method', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Models\Post::isPublished'))->toContain([
        'from' => PostController::class.'::show',
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
        'from' => 'Workbench\App\Models\Post::announcement',
        'to' => 'Workbench\App\Mail\PostAnnounced::__construct',
        'kind' => 'calls',
        'label' => 'new PostAnnounced(...)',
        'at' => 'workbench/app/Models/Post.php:61',
    ]);
});

it('records a static call to a scope against the model, under the name it is called by', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Models\Post::published'))->toContain([
        'from' => PostController::class.'::published',
        'to' => 'Workbench\App\Models\Post::published',
        'kind' => 'calls',
        'label' => 'calls static published()',
        'at' => 'workbench/app/Http/Controllers/PostController.php:17',
    ]);
});

it('records a scope called on the builder against its model', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Models\Post::published'))->toContain([
        'from' => PostController::class.'::published',
        'to' => 'Workbench\App\Models\Post::published',
        'kind' => 'calls',
        'label' => 'calls published()',
        'at' => 'workbench/app/Http/Controllers/PostController.php:17',
    ]);
});

it('records dispatching an app event or job as a call to its own member', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Events\PostPublished::dispatch'))->toContain([
        'from' => 'Workbench\App\Models\Post::booted',
        'to' => 'Workbench\App\Events\PostPublished::dispatch',
        'kind' => 'calls',
        'label' => 'calls static dispatch()',
        'at' => 'workbench/app/Models/Post.php:28',
    ]);
});

it('records a method an app trait declares against the class using it, from the method around the closure', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Models\Post::nothing'))->toContain([
        'from' => PostController::class.'::nothing',
        'to' => 'Workbench\App\Models\Post::nothing',
        'kind' => 'calls',
        'label' => 'calls nothing()',
        'at' => 'workbench/app/Http/Controllers/PostController.php:22',
    ]);
});

it('records a property fetch, naming the guard only when the receiver can be null', function () {
    $graph = updatedGraph();

    expect($graph->edgesTo('Workbench\App\Models\Post::title'))->toContain([
        'from' => PostController::class.'::show',
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

it('never records a framework member Larastan reports as declared on the model', function () {
    $graph = updatedGraph();

    expect($graph->edgesTo('Workbench\App\Models\Post::where'))->toBe([])
        ->and($graph->edgesTo('Workbench\App\Models\Post::count'))->toBe([])
        ->and($graph->edgesTo('Workbench\App\Models\Post::factory'))->toBe([]);
});

it('never records a member outside the app namespace, and never a member consuming itself', function () {
    $graph = updatedGraph();
    $symbols = array_filter($graph->edges, fn (array $edge) => in_array($edge['kind'], ['calls', 'fetches'], true));

    expect($symbols)->not->toBeEmpty();

    foreach ($symbols as $edge) {
        expect($edge['to'])->toStartWith('Workbench\App\\')
            ->and($edge['to'])->not->toBe($edge['from']);
    }
});

it('records a member reading another member of its own class, the hop a walk needs', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Models\Post::title'))->toContain([
        'from' => 'Workbench\App\Models\Post::getTitleLabelAttribute',
        'to' => 'Workbench\App\Models\Post::title',
        'kind' => 'fetches',
        'label' => 'fetches title',
        'at' => 'workbench/app/Models/Post.php:72',
    ]);
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

it('records a resource collection call as the app\'s own member, the way it records dispatch', function () {
    expect(updatedGraph()->edgesTo('Workbench\App\Http\Resources\PostResource::collection'))->toContain([
        'from' => 'Workbench\App\Http\Controllers\PostSummaryController::index',
        'to' => 'Workbench\App\Http\Resources\PostResource::collection',
        'kind' => 'calls',
        'label' => 'calls static collection()',
        'at' => 'workbench/app/Http/Controllers/PostSummaryController.php:20',
    ]);
});

it('labels a fetch inside a cache key with the sink it feeds, and not the same fetch elsewhere', function () {
    $fromTheCache = array_filter(updatedGraph()->edgesTo('Workbench\App\Models\Post::title'), fn (array $edge) => str_starts_with((string) $edge['at'], 'workbench/app/Support/PostCache.php'));

    expect(array_column($fromTheCache, 'label', 'at'))->toBe([
        'workbench/app/Support/PostCache.php:19' => 'fetches title (cache key)',
        'workbench/app/Support/PostCache.php:21' => 'fetches title',
    ]);
});

it('labels a fetch inside a storage path, keeping the null guard in the same bracket', function () {
    $graph = updatedGraph();
    $fromTheCache = fn (string $member) => array_column(array_filter($graph->edgesTo($member), fn (array $edge) => str_starts_with((string) $edge['at'], 'workbench/app/Support/PostCache.php')), 'label', 'at');

    expect($fromTheCache('Workbench\App\Models\Author::id'))->toBe(['workbench/app/Support/PostCache.php:28' => 'fetches id (unguarded, storage path)'])
        ->and($fromTheCache('Workbench\App\Models\Comment::body'))->toBe(['workbench/app/Support/PostCache.php:29' => 'fetches body'])
        ->and($graph->meta['symbols']['sinks'])->toBe(6);
});
