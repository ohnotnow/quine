<?php

declare(strict_types=1);

use Workbench\App\Models\Author;
use Workbench\App\Models\Comment;
use Workbench\App\Models\Concerns\Nothing;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

it('describes belongsTo relations with their foreign key nullability', function () {
    $relations = updatedGraph()->models[Comment::class]['relations'];

    expect($relations['author'])->toBe([
        'type' => 'BelongsTo',
        'related' => Author::class,
        'foreign_key' => 'comments.author_id',
        'nullable' => true,
    ])->and($relations['post']['nullable'])->toBeFalse();
});

it('describes belongsToMany pivots and hasMany foreign keys', function () {
    $models = updatedGraph()->models;

    expect($models[Post::class]['relations']['tags']['pivot'])->toBe('post_tag')
        ->and($models[Author::class]['relations']['posts']['foreign_key'])->toBe('posts.author_id');
});

it('lists accessors and joins the schema columns onto the model', function () {
    $comment = updatedGraph()->models[Comment::class];

    expect($comment['accessors'])->toContain('excerpt')
        ->and($comment['columns']['author_id']['nullable'])->toBeTrue();
});

it('emits a relation edge with the file and line of the relation method', function () {
    expect(updatedGraph()->edgesFrom(Comment::class, 'relation'))->toContain([
        'from' => Comment::class,
        'to' => Author::class,
        'kind' => 'relation',
        'label' => 'author() BelongsTo',
        'at' => 'workbench/app/Models/Comment.php:24',
    ]);
});

it('skips a non-model class under the Models directory', function () {
    $models = updatedGraph()->models;

    expect($models)->toHaveKeys([Author::class, Comment::class, Post::class, Tag::class])
        ->and($models)->not->toHaveKey(Nothing::class);
});
