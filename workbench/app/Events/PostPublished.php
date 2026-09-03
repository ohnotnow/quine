<?php

namespace Workbench\App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class PostPublished
{
    use Dispatchable;

    public function __construct(public int $postId) {}
}
