<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Sources;

use Illuminate\Support\Facades\Event;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Project;
use Ohwhatnow\Quine\Support\Describe;

/**
 * The closures and observers registered on each model's Eloquent events.
 * Invisible from outside the model file, but the dispatcher knows them all
 * once ModelsSource has instantiated (and so booted) every model.
 */
final class ModelEventsSource implements Source
{
    public function collect(Project $project, Graph $graph): void
    {
        foreach (Event::getRawListeners() as $event => $listeners) {
            if (! str_starts_with($event, 'eloquent.') || ! str_contains($event, ': ')) {
                continue;
            }

            [$hook, $class] = explode(': ', $event, 2);

            if (! array_key_exists($class, $graph->models)) {
                continue;
            }

            foreach ($listeners as $listener) {
                $graph->edge($class, Describe::callable($listener, $project), 'model-event', substr($hook, strlen('eloquent.')), null);
            }
        }
    }
}
