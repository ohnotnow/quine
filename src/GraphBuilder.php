<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Ohffs\Quine\Sources\Source;

final readonly class GraphBuilder
{
    /**
     * @param  list<Source>  $sources  Run in order; later sources may read what earlier ones wrote.
     */
    public function __construct(
        private Project $project,
        private array $sources,
    ) {}

    public function build(): Graph
    {
        $graph = new Graph;
        $graph->meta['built_at'] = now()->toIso8601String();
        $graph->meta['fingerprint'] = Fingerprint::of($this->project);

        $started = hrtime(true);

        foreach ($this->sources as $source) {
            $before = hrtime(true);
            $source->collect($this->project, $graph);
            $graph->meta['timings'][class_basename($source)] = round((hrtime(true) - $before) / 1e9, 2);
        }

        $graph->meta['built_in'] = round((hrtime(true) - $started) / 1e9, 2);

        return $graph;
    }
}
