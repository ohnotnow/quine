<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Sources;

use Illuminate\Support\Facades\Gate;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Project;
use Ohwhatnow\Quine\Support\Describe;

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
