<?php

declare(strict_types=1);

use Workbench\App\Livewire\CommentBox;

it('emits a component edge from a template to the anonymous component it renders', function () {
    expect(updatedGraph()->edgesFrom('workbench/resources/views/posts/show.blade.php', 'component'))->toContain([
        'from' => 'workbench/resources/views/posts/show.blade.php',
        'to' => 'workbench/resources/views/components/layouts/app.blade.php',
        'kind' => 'component',
        'label' => '<x-layouts.app>',
        'at' => 'workbench/resources/views/posts/show.blade.php:1',
    ]);
});

it('emits include, gate and flux edges from a template', function () {
    $edges = updatedGraph()->edgesFrom('workbench/resources/views/posts/show.blade.php');

    expect($edges)->toContain([
        'from' => 'workbench/resources/views/posts/show.blade.php',
        'to' => 'workbench/resources/views/posts/comments.blade.php',
        'kind' => 'include',
        'label' => 'posts.comments',
        'at' => 'workbench/resources/views/posts/show.blade.php:10',
    ])->toContain([
        'from' => 'workbench/resources/views/posts/show.blade.php',
        'to' => 'gate:editor',
        'kind' => 'gate',
        'label' => "@can('editor')",
        'at' => 'workbench/resources/views/posts/show.blade.php:5',
    ])->toContain([
        'from' => 'workbench/resources/views/posts/show.blade.php',
        'to' => 'flux:button',
        'kind' => 'flux',
        'label' => '<flux:button>',
        'at' => 'workbench/resources/views/posts/show.blade.php:7',
    ]);
});

it('emits a reads edge per property chain, labelled unguarded or null-safe', function () {
    $reads = updatedGraph()->edgesFrom('workbench/resources/views/posts/comments.blade.php', 'reads');

    expect($reads)->toContain([
        'from' => 'workbench/resources/views/posts/comments.blade.php',
        'to' => '$comment->author->name',
        'kind' => 'reads',
        'label' => 'unguarded',
        'at' => 'workbench/resources/views/posts/comments.blade.php:4',
    ])->toContain([
        'from' => 'workbench/resources/views/posts/comments.blade.php',
        'to' => '$comment->author?->name',
        'kind' => 'reads',
        'label' => 'null-safe',
        'at' => 'workbench/resources/views/posts/comments.blade.php:5',
    ]);
});

it('resolves a livewire tag by naming convention, labelled as a heuristic, only when the class exists', function () {
    $livewire = updatedGraph()->edgesFrom('workbench/resources/views/posts/comments.blade.php', 'livewire');

    expect($livewire)->toBe([[
        'from' => 'workbench/resources/views/posts/comments.blade.php',
        'to' => CommentBox::class,
        'kind' => 'livewire',
        'label' => '<livewire:comment-box> (heuristic: naming convention)',
        'at' => 'workbench/resources/views/posts/comments.blade.php:11',
    ]]);
});

it('does not scan templates under a vendor directory', function () {
    $graph = updatedGraph();

    expect($graph->edgesFrom('workbench/resources/views/vendor/somepackage/thing.blade.php'))->toBe([]);
});
