<?php

namespace Workbench\App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Workbench\App\Events\PostPublished;

class NotifyEditors implements ShouldQueue
{
    public function handle(PostPublished $event): void
    {
        //
    }
}
