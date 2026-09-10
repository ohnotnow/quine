<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use Ohffs\Quine\Support\Describe;

/**
 * App events to their listeners, straight from the dispatcher, flagged when
 * the listener is queued (so the effect lands later, off the request).
 */
final class ListenersSource implements Source
{
    public function collect(Project $project, Graph $graph): void
    {
        foreach (Event::getRawListeners() as $event => $listeners) {
            if (str_starts_with($event, 'eloquent.')) {
                continue;
            }

            foreach ($listeners as $listener) {
                $target = $this->target($listener, $project);

                if (! str_starts_with($event, $project->namespace) && ! str_starts_with($target, $project->namespace)) {
                    continue;
                }

                $graph->edge($event, $target, 'event', $this->isQueued($listener) ? 'queued listener' : 'listener', null);
            }
        }
    }

    /**
     * A bare class string means "@handle" to the dispatcher, so say so.
     */
    private function target(mixed $listener, Project $project): string
    {
        $target = Describe::callable($listener, $project);

        if (is_string($listener) && class_exists($listener) && ! str_contains($listener, '@')) {
            return $target.'@handle';
        }

        return $target;
    }

    private function isQueued(mixed $listener): bool
    {
        return is_string($listener) && is_subclass_of(Str::before($listener, '@'), ShouldQueue::class);
    }
}
