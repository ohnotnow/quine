<?php

declare(strict_types=1);

namespace Ohffs\Quine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Ohffs\Quine\Change;
use Ohffs\Quine\Graph;
use Ohffs\Quine\GraphBuilder;
use Ohffs\Quine\Project;
use Ohffs\Quine\Recipes\Registry;

class AskCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'quine:ask {node : A class name, class basename, or file path} {--depth=2 : How many hops to walk} {--full : List every uses edge instead of a count}';

    /**
     * The command description.
     */
    protected $description = 'What is near this node, by what kind of edge, and what should I check before editing it?';

    /**
     * Edge kinds too noisy for a neighbourhood.
     */
    private const array SKIP = ['reads', 'flux'];

    /**
     * Print order: the kinds nobody would find by reading one file come first,
     * plain static references last.
     */
    private const array ORDER = ['relation', 'model-event', 'event', 'gate', 'policy', 'schedule', 'livewire', 'component', 'include', 'route', 'renders', 'uses'];

    public function handle(GraphBuilder $builder, Project $project, Registry $recipes): int
    {
        $graph = $this->graph($builder, $project);
        $node = $this->resolve($this->argument('node'), $graph, $project);

        if ($node === null) {
            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=yellow>NEIGHBOURHOOD</>', $node);
        $this->walk($node, $graph, max(1, (int) $this->option('depth')));

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>NUDGES</>', 'what to check before you edit');

        $nudges = [];

        foreach ($this->modelsWithin($node, $graph) as $model) {
            $nudges = [...$nudges, ...$recipes->nudges(Change::forNode($model), $graph)];
        }

        $nudges = array_values(array_unique($nudges));

        foreach ($nudges === [] ? ['nothing to add'] : $nudges as $nudge) {
            $this->line('    '.$nudge);
        }

        return self::SUCCESS;
    }

    private function graph(GraphBuilder $builder, Project $project): Graph
    {
        if (is_file($project->graphPath)) {
            return Graph::load($project->graphPath);
        }

        $graph = $builder->build();
        $graph->save($project->graphPath);
        $this->line('no graph at '.$project->relative($project->graphPath).', built it first');

        return $graph;
    }

    /**
     * The node itself when it is a model, plus every model one relation hop
     * away in either direction: a nullable key on a neighbour is exactly the
     * kind of thing a reader of the asked-for model would miss.
     *
     * @return list<string>
     */
    private function modelsWithin(string $node, Graph $graph): array
    {
        $models = array_key_exists($node, $graph->models) ? [$node] : [];

        foreach ($graph->edgesFrom($node, 'relation') as $edge) {
            $models[] = $edge['to'];
        }

        foreach ($graph->edgesTo($node, 'relation') as $edge) {
            $models[] = $edge['from'];
        }

        return array_values(array_unique(array_filter($models, fn (string $model) => array_key_exists($model, $graph->models))));
    }

    /**
     * The graph node the argument names: exactly, by unique class basename, or as a file path.
     */
    private function resolve(string $argument, Graph $graph, Project $project): ?string
    {
        $nodes = array_keys($graph->models);

        foreach ($graph->edges as $edge) {
            $nodes[] = $edge['from'];
            $nodes[] = $edge['to'];
        }

        $nodes = array_unique($nodes);

        if (in_array($argument, $nodes, true)) {
            return $argument;
        }

        $byBasename = array_values(array_filter($nodes, fn (string $node) => str_contains($node, '\\') && class_basename($node) === $argument));

        if (count($byBasename) === 1) {
            return $byBasename[0];
        }

        if (count($byBasename) > 1) {
            $this->error("$argument is ambiguous, it matches:");

            foreach ($byBasename as $node) {
                $this->line("    $node");
            }

            return null;
        }

        $path = $project->relative($project->absolute($argument));

        if (in_array($path, $nodes, true)) {
            return $path;
        }

        $this->error("nothing in the graph matches $argument");

        return null;
    }

    /**
     * Breadth-first over the edges in both directions, printing each edge once,
     * indented by the hop at which it was reached. Beyond the root, the edges
     * of each expanded node sit under a "via <node>:" line so a deeper edge
     * always says where it hangs off. Within a node the edges are grouped by
     * kind in ORDER, and uses edges collapse to a count unless --full.
     */
    private function walk(string $start, Graph $graph, int $maxDepth): void
    {
        $depth = [$start => 0];
        $queue = [$start];
        $printed = [];
        $full = (bool) $this->option('full');

        while ($queue !== []) {
            $current = array_shift($queue);
            $hop = $depth[$current] + 1;

            if ($depth[$current] >= $maxDepth) {
                continue;
            }

            $found = [];

            foreach ($graph->edges as $index => $edge) {
                if (isset($printed[$index]) || in_array($edge['kind'], self::SKIP, true)) {
                    continue;
                }

                [$arrow, $other] = match ($current) {
                    $edge['from'] => ['->', $edge['to']],
                    $edge['to'] => ['<-', $edge['from']],
                    default => [null, null],
                };

                if ($arrow === null || $other === null) {
                    continue;
                }

                $printed[$index] = true;
                $found[] = [$arrow, $other, $edge];

                if (! isset($depth[$other])) {
                    $depth[$other] = $hop;
                    $queue[] = $other;
                }
            }

            usort($found, fn (array $a, array $b) => $this->rank($a[2]['kind']) <=> $this->rank($b[2]['kind']));

            $lines = [];
            $collapsed = ['<-' => 0, '->' => 0];
            $onlyCounts = $current !== $start;

            foreach ($found as [$arrow, $other, $edge]) {
                if ($edge['kind'] === 'uses' && ! $full) {
                    $collapsed[$arrow]++;

                    continue;
                }

                $onlyCounts = false;
                $lines[] = str_repeat('    ', $hop)."$arrow [{$edge['kind']}: {$edge['label']}] $other".($edge['at'] === null ? '' : "  <fg=gray>{$edge['at']}</>");
            }

            // Beyond the root, a block holding nothing but counts is padding: the
            // root's own count already covers those classes.
            if ($onlyCounts) {
                continue;
            }

            if ($collapsed['<-'] > 0) {
                $lines[] = str_repeat('    ', $hop).'<fg=gray><- referenced by '.$collapsed['<-'].' '.Str::plural('class', $collapsed['<-']).' (uses; --full lists them)</>';
            }

            if ($collapsed['->'] > 0) {
                $lines[] = str_repeat('    ', $hop).'<fg=gray>-> references '.$collapsed['->'].' '.Str::plural('class', $collapsed['->']).' (uses; --full lists them)</>';
            }

            if ($lines !== [] && $current !== $start) {
                $this->line(str_repeat('    ', $depth[$current])."<fg=gray>via</> $current:");
            }

            foreach ($lines as $line) {
                $this->line($line);
            }
        }
    }

    private function rank(string $kind): int
    {
        $rank = array_search($kind, self::ORDER, true);

        return $rank === false ? count(self::ORDER) : $rank;
    }
}
