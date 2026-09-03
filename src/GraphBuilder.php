<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine;

use Ohwhatnow\Quine\Sources\Source;

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

        foreach ($this->sources as $source) {
            $source->collect($this->project, $graph);
        }

        return $graph;
    }
}
