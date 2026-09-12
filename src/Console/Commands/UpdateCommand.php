<?php

declare(strict_types=1);

namespace Ohffs\Quine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Ohffs\Quine\Console\Summary;
use Ohffs\Quine\Graph;
use Ohffs\Quine\GraphBuilder;
use Ohffs\Quine\Project;
use Ohffs\Quine\Rebuild;
use Ohffs\Quine\Recipes\Registry;

class UpdateCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'quine:update {--json= : Write the graph here instead of config quine.graph_path}';

    /**
     * The command description.
     */
    protected $description = 'Build the graph of the hidden edges in this application and write it as JSON.';

    public function handle(GraphBuilder $builder, Project $project, Registry $recipes): int
    {
        $graph = $builder->build();

        $json = $this->option('json');
        $path = $project->absolute(is_string($json) && $json !== '' ? $json : $project->graphPath);

        $graph->save($path);
        (new Filesystem)->delete((new Rebuild($project))->lockPath());

        if ($this->output->isQuiet()) {
            return self::SUCCESS;
        }

        $summary = new Summary($this, $graph);
        $summary->models();
        $summary->hiddenEdges();
        $summary->pages();
        $summary->nudges($recipes);

        $this->newLine();
        $this->info(count($graph->edges).' edges written to '.$project->relative($path));

        if ($this->output->isVerbose()) {
            $this->timings($graph);
        }

        return self::SUCCESS;
    }

    /**
     * How long each source took, slowest first, then the total: the numbers
     * a decision about building in parallel has to rest on.
     */
    private function timings(Graph $graph): void
    {
        $timings = is_array($graph->meta['timings'] ?? null) ? array_filter($graph->meta['timings'], 'is_float') : [];
        arsort($timings);

        $this->newLine();
        $this->table(['source', 'seconds'], [
            ...array_map(fn (string $source, float $seconds) => [$source, number_format($seconds, 2)], array_keys($timings), $timings),
            ['total', number_format(is_float($graph->meta['built_in'] ?? null) ? $graph->meta['built_in'] : 0.0, 2)],
        ]);
    }
}
