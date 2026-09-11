<?php

declare(strict_types=1);

namespace Ohffs\Quine\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Ohffs\Quine\Change;
use Ohffs\Quine\Differ;
use Ohffs\Quine\EditDiffer;
use Ohffs\Quine\Fingerprint;
use Ohffs\Quine\Graph;
use Ohffs\Quine\GraphBuilder;
use Ohffs\Quine\Project;
use Ohffs\Quine\Reach;
use Ohffs\Quine\Recipes\Registry;
use Symfony\Component\Console\Input\StreamableInputInterface;
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
    protected $signature = 'quine:nudge {file : Absolute or relative path of the edited file}
                                        {--edit : Read the edit from stdin as JSON {"old": string|null, "new": string} instead of diffing against git}';

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
            $change = Change::forFile($path, ($this->editFromStdin($project) ?? $differ)->diff($path));
            ['callers' => $callers, 'reach' => $reach, 'gaps' => $gaps, 'hidden' => $hidden, 'notes' => $notes] = (new Reach($graph, $project))->digest($change);
            $nudges = array_map('strval', $recipes->nudges($change, $graph));

            if ($callers === [] && $reach === [] && $gaps === [] && $hidden === [] && $notes === [] && $nudges === []) {
                return self::SUCCESS;
            }

            // "hang on" is earned by a broken caller, somewhere the walk reached, a gap, a hidden edge or a recipe; the rest is for the record.
            $this->line(($callers !== [] || $reach !== [] || $gaps !== [] || $hidden !== [] || $nudges !== [] ? 'Quine: hang on. ' : 'Quine: fyi. ').$path);

            foreach ([...$callers, ...$reach, ...$gaps, ...$hidden, ...$notes, ...$nudges] as $line) {
                $this->line($line);
            }
        } catch (Throwable $e) {
            $output = $this->output->getOutput();
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('quine: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * With --edit, the differ built from the JSON edit on stdin; without it, null.
     */
    private function editFromStdin(Project $project): ?Differ
    {
        if (! $this->option('edit')) {
            return null;
        }

        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $edit = json_decode((string) stream_get_contents($stream ?? STDIN), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($edit) || ! is_string($edit['new'] ?? null) || ! (is_string($edit['old'] ?? null) || ($edit['old'] ?? null) === null)) {
            throw new InvalidArgumentException('--edit expects JSON {"old": string|null, "new": string} on stdin');
        }

        return new EditDiffer($project, $edit['old'] ?? null, $edit['new']);
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
