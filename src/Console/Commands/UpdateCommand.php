<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Console\Commands;

use Illuminate\Console\Command;
use Ohwhatnow\Quine\Console\Summary;
use Ohwhatnow\Quine\GraphBuilder;
use Ohwhatnow\Quine\Project;
use Ohwhatnow\Quine\Recipes\Registry;

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

        return self::SUCCESS;
    }
}
