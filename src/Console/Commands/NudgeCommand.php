<?php

declare(strict_types=1);

namespace Ohffs\Quine\Console\Commands;

use Illuminate\Console\Command;
use Ohffs\Quine\Change;
use Ohffs\Quine\Differ;
use Ohffs\Quine\Fingerprint;
use Ohffs\Quine\Graph;
use Ohffs\Quine\GraphBuilder;
use Ohffs\Quine\Project;
use Ohffs\Quine\Reach;
use Ohffs\Quine\Recipes\Registry;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

/**
 * The hook's door: after one edit, say only what the recipes have to say.
 * Never fails, never nags; silence is the default.
 */
class NudgeCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'quine:nudge {file : Absolute or relative path of the edited file}';

    /**
     * The command description.
     */
    protected $description = 'Print the nudges the recipes have for one edited file, rebuilding the graph if the app has changed.';

    public function handle(GraphBuilder $builder, Project $project, Registry $recipes, Differ $differ): int
    {
        try {
            $path = $this->within($this->argument('file'), $project);

            if ($path === null) {
                return self::SUCCESS;
            }

            $graph = $this->freshGraph($builder, $project);
            ['gaps' => $gaps, 'hidden' => $hidden, 'notes' => $notes] = (new Reach($graph, $project))->digest($path);
            $nudges = array_map('strval', $recipes->nudges(Change::forFile($path, $differ->diff($path)), $graph));

            if ($gaps === [] && $hidden === [] && $notes === [] && $nudges === []) {
                return self::SUCCESS;
            }

            // "hang on" is earned by a gap, a hidden edge or a recipe; the rest is for the record.
            $this->line(($gaps !== [] || $hidden !== [] || $nudges !== [] ? 'Quine: hang on. ' : 'Quine: fyi. ').$path);

            foreach ([...$gaps, ...$hidden, ...$notes, ...$nudges] as $line) {
                $this->line($line);
            }
        } catch (Throwable $e) {
            $output = $this->output->getOutput();
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('quine: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * The path relative to the project, or null when it lies outside it.
     */
    private function within(string $file, Project $project): ?string
    {
        $relative = $project->relative($project->absolute($file));

        return str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative) === 1 ? null : $relative;
    }

    /**
     * The saved graph when the app's shape has not changed since it was built, else a rebuild.
     */
    private function freshGraph(GraphBuilder $builder, Project $project): Graph
    {
        $fingerprint = Fingerprint::of($project);

        try {
            $graph = is_file($project->graphPath) ? Graph::load($project->graphPath) : null;
        } catch (Throwable) {
            $graph = null;
        }

        if ($graph !== null && ($graph->meta['fingerprint'] ?? null) === $fingerprint) {
            return $graph;
        }

        $graph = $builder->build();
        $graph->save($project->graphPath);

        return $graph;
    }
}
