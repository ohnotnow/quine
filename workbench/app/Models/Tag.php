<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Workbench\Database\Factories\TagFactory;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['name'];

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }

    /** @return BelongsToMany<Post, $this> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }
}
