<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;

interface Source
{
    public function collect(Project $project, Graph $graph): void;
}
