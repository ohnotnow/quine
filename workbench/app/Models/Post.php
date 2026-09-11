<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workbench\App\Events\PostPublished;
use Workbench\App\Mail\PostAnnounced;
use Workbench\App\Models\Concerns\Nothing;
use Workbench\Database\Factories\PostFactory;

class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use Nothing;

    protected $fillable = ['author_id', 'title', 'body'];

    protected static function booted(): void
    {
        static::created(fn (Post $post) => PostPublished::dispatch($post->id));
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }

    public function isPublished(): bool
    {
        return $this->created_at !== null;
    }

    /** @return BelongsTo<Author, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function announcement(): PostAnnounced
    {
        return new PostAnnounced($this);
    }

    /** @param Builder<Post> $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('created_at');
    }
}
