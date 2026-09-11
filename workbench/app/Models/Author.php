<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Workbench\Database\Factories\AuthorFactory;

class Author extends Authenticatable
{
    /** @use HasFactory<AuthorFactory> */
    use HasFactory;

    protected $fillable = ['name'];

    protected static function newFactory(): AuthorFactory
    {
        return AuthorFactory::new();
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
