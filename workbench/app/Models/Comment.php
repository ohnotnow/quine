<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Workbench\Database\Factories\CommentFactory;

class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    protected $fillable = ['author_id', 'post_id', 'body'];

    protected static function newFactory(): CommentFactory
    {
        return CommentFactory::new();
    }

    /** @return BelongsTo<Author, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    protected function excerpt(): Attribute
    {
        return Attribute::get(fn () => Str::limit($this->body, 20));
    }
}
