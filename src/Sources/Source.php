<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Sources;

use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Project;

interface Source
{
    public function collect(Project $project, Graph $graph): void;
}
