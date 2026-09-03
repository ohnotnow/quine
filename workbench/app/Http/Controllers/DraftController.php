<?php

namespace Workbench\App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Renders a view that does not exist, so quine has a missing-template fixture.
 */
class DraftController
{
    public function index(): View
    {
        return view('does.not.exist');
    }
}
