<?php

namespace Workbench\App\Models\Concerns;

/**
 * Deliberately not a model: quine must skip it without complaint.
 */
trait Nothing
{
    public function nothing(): string
    {
        return '';
    }
}
