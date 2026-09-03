<?php

namespace Workbench\App\Policies;

use Workbench\App\Models\Author;
use Workbench\App\Models\Post;

class PostPolicy
{
    public function view(Author $author, Post $post): bool
    {
        return true;
    }
}
