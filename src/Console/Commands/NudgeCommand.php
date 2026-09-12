<?php

declare(strict_types=1);

namespace Ohffs\Quine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Ohffs\Quine\Change;
use Ohffs\Quine\Differ;
use Ohffs\Quine\EditDiffer;
use Ohffs\Quine\Fingerprint;
use Ohffs\Quine\Graph;
use Ohffs\Quine\GraphBuilder;
use Ohffs\Quine\Nudger;
use Ohffs\Quine\Project;
use Ohffs\Quine\Reach;
use Ohffs\Quine\Rebuild;
use Ohffs\Quine\Session;
use Ohffs\Quine\SnapshotDiffer;
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
    protected $signature = 'quine:nudge {file? : Absolute or relative path of the edited file}
                                        {--edit : Read the edit from stdin as JSON {"old": string|null, "new": string} instead of diffing against git}
                                        {--session= : Nudge every file changed since this session last asked, remembering each as it now stands}';

    /**
     * The command description.
     */
    protected $description = 'Say what an edit touches and what checking it costs, from the saved graph; builds the graph only when there is none.';

    public function handle(GraphBuilder $builder, Project $project, Nudger $nudger, Differ $differ, Rebuild $rebuild): int
    {
        try {
            $session = $this->option('session');
            $file = $this->argument('file');

            if (is_string($session) && $session !== '') {
                if ($this->option('edit')) {
                    throw new InvalidArgumentException('--session and --edit are different doors: pass one');
                }

                if (is_string($file)) {
                    $this->error('quine: --session scans the tree; the file argument is ignored');
                }

                return $this->scan(new Session($project, $session), $builder, $project, $nudger, $rebuild);
            }

            if (! is_string($file)) {
                $this->line('quine:nudge <file> [--edit], or quine:nudge --session=<id>');

                return self::SUCCESS;
            }

            $path = $this->within($file, $project);

            if ($path === null) {
                return self::SUCCESS;
            }

            ['graph' => $graph, 'stale' => $stale] = $this->savedGraph($builder, $project);
            $change = Change::forFile($path, ($this->editFromStdin($project) ?? $differ)->diff($path));
            $lines = $nudger->lines($change, $graph);

            if ($stale) {
                $rebuild->start();
            }

            if ($lines === []) {
                return self::SUCCESS;
            }

            $this->print($lines);
        } catch (Throwable $e) {
            $output = $this->output->getOutput();
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('quine: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * Every file under the watched paths touched since the session's marker
     * and different from its snapshot gets a block, in path order. The first
     * run only plants the marker: nothing before it is this session's doing.
     * A file the graph has no node for, or a stale fingerprint, starts a
     * rebuild in the background; nobody is asked to. Deletions are not seen
     * (a deleted file is not there to be found) and are not nudged yet.
     */
    private function scan(Session $session, GraphBuilder $builder, Project $project, Nudger $nudger, Rebuild $rebuild): int
    {
        $start = time();
        $marker = $session->marker();

        if ($marker === null) {
            $session->touch($start);

            return self::SUCCESS;
        }

        $files = new Filesystem;
        $changed = [];

        foreach ($project->watchedPaths() as $directory) {
            if (! $files->isDirectory($directory)) {
                continue;
            }

            foreach ($files->allFiles($directory) as $file) {
                if ($file->getMTime() >= $marker) {
                    $changed[$project->relative($file->getPathname())] = $file->getContents();
                }
            }
        }

        ksort($changed);
        $graph = null;
        $reach = null;
        $stale = false;
        $printed = false;
        $unknown = false;

        foreach ($changed as $relative => $current) {
            $baseline = $session->snapshot($relative) ?? $this->headCopy($project, $relative);

            if ($baseline === $current) {
                continue;
            }

            if ($graph === null || $reach === null) {
                ['graph' => $graph, 'stale' => $stale] = $this->savedGraph($builder, $project);
                $reach = new Reach($graph, $project);
            }

            $lines = $nudger->lines(Change::forFile($relative, (new SnapshotDiffer($project, $baseline))->diff($relative)), $graph);

            if ($lines !== []) {
                if ($printed) {
                    $this->line('');
                }

                $this->print($lines);
                $printed = true;
            }

            $unknown = $unknown || $reach->knows($relative) === false;
            $session->remember($relative, $current);
        }

        if ($unknown || $stale) {
            $rebuild->start();
        }

        $session->touch($start);

        return self::SUCCESS;
    }

    /**
     * The file as the last commit has it, or null when git cannot say (untracked, no repo, no git).
     */
    private function headCopy(Project $project, string $relative): ?string
    {
        try {
            $result = Process::path($project->basePath)->run(['git', 'show', "HEAD:$relative"]);

            return $result->successful() ? $result->output() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function print(array $lines): void
    {
        foreach ($lines as $line) {
            $this->line($line);
        }
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
     * The saved graph, and whether the app has changed shape since it was
     * built. Only when there is no graph at all is one built here: a rebuild
     * takes tens of seconds on a real app, and the hook that calls this has
     * a budget of twenty, so answering from a stale graph beats saying nothing.
     *
     * @return array{graph: Graph, stale: bool}
     */
    private function savedGraph(GraphBuilder $builder, Project $project): array
    {
        try {
            $graph = is_file($project->graphPath) ? Graph::load($project->graphPath) : null;
        } catch (Throwable) {
            $graph = null;
        }

        if ($graph !== null) {
            return ['graph' => $graph, 'stale' => ($graph->meta['fingerprint'] ?? null) !== Fingerprint::of($project)];
        }

        $graph = $builder->build();
        $graph->save($project->graphPath);

        return ['graph' => $graph, 'stale' => false];
    }
}
