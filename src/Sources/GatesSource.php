<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Illuminate\Support\Facades\Gate;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use Ohffs\Quine\Support\Describe;

/**
 * Gate abilities and their defining callbacks, and model to policy mappings.
 */
final class GatesSource implements Source
{
    public function collect(Project $project, Graph $graph): void
    {
        foreach (Gate::abilities() as $name => $callback) {
            $graph->edge("gate:$name", Describe::callable($callback, $project), 'gate', 'defined by', null);
        }

        foreach (Gate::policies() as $model => $policy) {
            $graph->edge($model, $policy, 'policy', 'policy', null);
        }
    }
}
