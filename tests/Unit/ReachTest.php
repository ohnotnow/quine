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

/**
 * A graph where Post::isPublished is consumed by a routed action through a chain of plain classes.
 */
function reachGraph(): Graph
{
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('Workbench\App\Support\A::x', 'Workbench\App\Models\Post::isPublished', 'calls', 'calls isPublished()', 'workbench/app/Support/A.php:10');
    $graph->edge('Workbench\App\Support\B::y', 'Workbench\App\Support\A::x', 'calls', 'calls x()', 'workbench/app/Support/B.php:10');
    $graph->edge('Workbench\App\Support\A::x', 'Workbench\App\Support\B::y', 'calls', 'calls y()', 'workbench/app/Support/A.php:11');
    $graph->edge('Workbench\App\Http\Controllers\C::show', 'Workbench\App\Support\B::y', 'calls', 'calls y()', 'workbench/app/Http/Controllers/C.php:10');
    $graph->edge('route GET|HEAD /c [c.show]', 'Workbench\App\Http\Controllers\C', 'route', 'show web', null);

    return $graph;
}

it('walks through a cycle once and reaches the routed action beyond it', function () {
    $walk = (new Reach(reachGraph(), app(Project::class)))->walk('Workbench\App\Models\Post::isPublished');

    expect($walk['endpoints'])->toBe([[
        'node' => 'Workbench\App\Http\Controllers\C::show',
        'kind' => 'route GET|HEAD /c',
        'trail' => ['Workbench\App\Models\Post::isPublished', 'Workbench\App\Support\A::x', 'Workbench\App\Support\B::y'],
        'at' => 'workbench/app/Support/A.php:10',
        'coverage' => 'no test covers workbench/app/Http/Controllers/C.php',
    ]])
        ->and($walk['frontiers'])->toBe([])
        ->and($walk['stopped'])->toBeFalse();
});

it('prints ten endpoints for the hook and points at quine:ask for the rest', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';

    foreach (range(1, 12) as $n) {
        $graph->edge("Workbench\\App\\Http\\Controllers\\C$n::show", 'Workbench\App\Models\Post::isPublished', 'calls', 'calls isPublished()', "workbench/app/Http/Controllers/C$n.php:10");
        $graph->edge("route GET|HEAD /c$n [c$n.show]", "Workbench\\App\\Http\\Controllers\\C$n", 'route', 'show web', null);
    }

    $digest = (new Reach($graph, app(Project::class)))->digest(Change::forFile('workbench/app/Models/Post.php', "-    public function isPublished(): bool\n"));

    expect($digest['reach'])->toHaveCount(11)
        ->and($digest['reach'][0])->toBe('reaches route GET|HEAD /c1 (C1::show) via Post::isPublished -> C1::show (at workbench/app/Http/Controllers/C1.php:10); no test covers workbench/app/Http/Controllers/C1.php')
        ->and($digest['reach'][10])->toBe('and 2 more: quine:ask Post::isPublished lists them');
});

it('treats every method of a page-routed class, a Livewire full-page component, as the route action', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('Workbench\App\Livewire\Admin\Users::toggleAdmin', 'Workbench\App\Models\User::full_name', 'fetches', 'fetches full_name', 'workbench/app/Livewire/Admin/Users.php:207');
    $graph->edge('route GET|HEAD /admin/users [admin.users]', 'Workbench\App\Livewire\Admin\Users', 'route', 'page web,auth', null);

    $walk = (new Reach($graph, app(Project::class)))->walk('Workbench\App\Models\User::full_name');

    expect($walk['endpoints'])->toHaveCount(1)
        ->and($walk['endpoints'][0]['node'])->toBe('Workbench\App\Livewire\Admin\Users::toggleAdmin')
        ->and($walk['endpoints'][0]['kind'])->toBe('page GET|HEAD /admin/users')
        ->and($walk['frontiers'])->toBe([]);
});

it('follows a method nobody calls, such as a resource toArray, through whoever constructs or dispatches its class', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('Workbench\App\Http\Resources\NoteResource::toArray', 'Workbench\App\Models\User::full_name', 'fetches', 'fetches full_name', 'workbench/app/Http/Resources/NoteResource.php:23');
    $graph->edge('Workbench\App\Http\Controllers\NoteController::show', 'Workbench\App\Http\Resources\NoteResource::__construct', 'calls', 'new NoteResource(...)', 'workbench/app/Http/Controllers/NoteController.php:30');
    $graph->edge('route GET|HEAD /api/notes/{note} [notes.show]', 'Workbench\App\Http\Controllers\NoteController', 'route', 'show api', null);
    $graph->edge('Workbench\App\Jobs\Export::handle', 'Workbench\App\Models\User::full_name', 'fetches', 'fetches full_name', 'workbench/app/Jobs/Export.php:40');
    $graph->edge('Workbench\App\Http\Controllers\NoteController::export', 'Workbench\App\Jobs\Export::dispatch', 'calls', 'calls static dispatch()', 'workbench/app/Http/Controllers/NoteController.php:50');
    $graph->edge('route GET|HEAD /api/export [notes.export]', 'Workbench\App\Http\Controllers\NoteController', 'route', 'export api', null);

    $walk = (new Reach($graph, app(Project::class)))->walk('Workbench\App\Models\User::full_name');

    // Breadth first: the job is one hop away, the route three.
    expect(array_map(fn (array $endpoint) => [$endpoint['kind'], $endpoint['trail']], $walk['endpoints']))->toBe([
        ['job, by namespace', ['Workbench\App\Models\User::full_name']],
        ['route GET|HEAD /api/notes/{note}', ['Workbench\App\Models\User::full_name', 'Workbench\App\Http\Resources\NoteResource::toArray', 'Workbench\App\Http\Resources\NoteResource::__construct']],
    ])
        ->and($walk['frontiers'])->toBe([]);
});

it('prints the constructor bridge as new Class, and a namespace guess as by namespace', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('Workbench\App\Http\Resources\NoteResource::toArray', 'Workbench\App\Models\User::full_name', 'fetches', 'fetches full_name', 'workbench/app/Http/Resources/NoteResource.php:23');
    $graph->edge('Workbench\App\Http\Controllers\NoteController::show', 'Workbench\App\Http\Resources\NoteResource::__construct', 'calls', 'new NoteResource(...)', 'workbench/app/Http/Controllers/NoteController.php:30');
    $graph->edge('route GET|HEAD /api/notes/{note} [notes.show]', 'Workbench\App\Http\Controllers\NoteController', 'route', 'show api', null);
    $graph->edge('Workbench\App\Mcp\Tools\GetNote::handle', 'Workbench\App\Models\User::full_name', 'fetches', 'fetches full_name', 'workbench/app/Mcp/Tools/GetNote.php:37');

    expect((new Reach($graph, app(Project::class)))->reachLines('Workbench\App\Models\User::full_name')['lines'])->toBe([
        'reaches Workbench\App\Mcp\Tools\GetNote (MCP tool, by namespace) via User::full_name -> GetNote::handle (at workbench/app/Mcp/Tools/GetNote.php:37); no test covers workbench/app/Mcp/Tools/GetNote.php',
        'reaches route GET|HEAD /api/notes/{note} (NoteController::show) via User::full_name -> NoteResource::toArray -> new NoteResource -> NoteController::show (at workbench/app/Http/Resources/NoteResource.php:23); no test covers workbench/app/Http/Controllers/NoteController.php',
    ]);
});

it('collapses the templates one trail reaches to a line naming what renders each', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('workbench/resources/views/a.blade.php', 'Workbench\App\Models\User::full_name', 'fetches', 'fetches full_name', 'workbench/resources/views/a.blade.php:4');
    $graph->edge('workbench/resources/views/b.blade.php', 'Workbench\App\Models\User::full_name', 'fetches', 'fetches full_name', 'workbench/resources/views/b.blade.php:9');
    $graph->coverage['workbench/resources/views/a.blade.php'] = ['workbench/tests/Feature/ATest.php', 'workbench/tests/Feature/AlsoTest.php'];

    expect((new Reach($graph, app(Project::class)))->reachLines('Workbench\App\Models\User::full_name')['lines'])->toBe([
        'reaches workbench/resources/views/a.blade.php:4 (ATest.php, AlsoTest.php render it), workbench/resources/views/b.blade.php:9 (no test renders this) via User::full_name; whether a test exercises your change is yours to check',
    ]);
});

it('prints the routes one trail reaches on one line, naming their actions', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('Workbench\App\Http\Resources\NoteResource::toArray', 'Workbench\App\Models\User::full_name', 'fetches', 'fetches full_name', 'workbench/app/Http/Resources/NoteResource.php:23');

    foreach (['store' => 'POST /api/notes', 'show' => 'GET|HEAD /api/notes/{note}', 'update' => 'PUT|PATCH /api/notes/{note}'] as $action => $route) {
        $graph->edge("Workbench\\App\\Http\\Controllers\\NoteController::$action", 'Workbench\App\Http\Resources\NoteResource::__construct', 'calls', 'new NoteResource(...)', 'workbench/app/Http/Controllers/NoteController.php:30');
        $graph->edge("route $route [notes.$action]", 'Workbench\App\Http\Controllers\NoteController', 'route', "$action api", null);
    }

    expect((new Reach($graph, app(Project::class)))->reachLines('Workbench\App\Models\User::full_name')['lines'])->toBe([
        'reaches routes POST /api/notes, GET|HEAD /api/notes/{note}, PUT|PATCH /api/notes/{note} (NoteController::store, show, update) via User::full_name -> NoteResource::toArray -> new NoteResource (at workbench/app/Http/Resources/NoteResource.php:23); no test covers workbench/app/Http/Controllers/NoteController.php',
    ]);
});

it('labels an MCP server as a server and a page route by its component and action', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('Workbench\App\Mcp\Servers\Main::instructionsFor', 'Workbench\App\Models\Note::inChannelsOf', 'calls', 'calls inChannelsOf()', 'workbench/app/Mcp/Servers/Main.php:51');
    $graph->edge('Workbench\App\Livewire\Admin\Users::toggleAdmin', 'Workbench\App\Models\Note::inChannelsOf', 'calls', 'calls inChannelsOf()', 'workbench/app/Livewire/Admin/Users.php:207');
    $graph->edge('route GET|HEAD /admin/users [admin.users]', 'Workbench\App\Livewire\Admin\Users', 'route', 'page web', null);

    expect((new Reach($graph, app(Project::class)))->reachLines('Workbench\App\Models\Note::inChannelsOf')['lines'])->toBe([
        'reaches Workbench\App\Mcp\Servers\Main (MCP server, by namespace) via Note::inChannelsOf -> Main::instructionsFor (at workbench/app/Mcp/Servers/Main.php:51); no test covers workbench/app/Mcp/Servers/Main.php',
        'reaches route GET|HEAD /admin/users (Users component, toggleAdmin) via Note::inChannelsOf -> Users::toggleAdmin (at workbench/app/Livewire/Admin/Users.php:207); no test covers workbench/app/Livewire/Admin/Users.php',
    ]);
});

it('follows a resource through collection() as well as its constructor', function () {
    $graph = new Graph;
    $graph->meta['coverage'] = 'ok';
    $graph->edge('Workbench\App\Http\Resources\PostResource::toArray', 'Workbench\App\Models\Post::title', 'fetches', 'fetches title', 'workbench/app/Http/Resources/PostResource.php:17');
    $graph->edge('Workbench\App\Http\Controllers\PostController::index', 'Workbench\App\Http\Resources\PostResource::collection', 'calls', 'calls static collection()', 'workbench/app/Http/Controllers/PostController.php:33');
    $graph->edge('route GET|HEAD /posts [posts.index]', 'Workbench\App\Http\Controllers\PostController', 'route', 'index web', null);

    expect((new Reach($graph, app(Project::class)))->reachLines('Workbench\App\Models\Post::title')['lines'])->toBe([
        'reaches route GET|HEAD /posts (PostController::index) via Post::title -> PostResource::toArray -> PostResource::collection -> PostController::index (at workbench/app/Http/Resources/PostResource.php:17); no test covers workbench/app/Http/Controllers/PostController.php',
    ]);
});
