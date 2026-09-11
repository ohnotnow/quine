<?php

namespace Workbench\App\Mail;

use Illuminate\Mail\Mailable;
use Workbench\App\Models\Post;

class PostAnnounced extends Mailable
{
    public function __construct(public Post $post) {}
}
