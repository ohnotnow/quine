<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Sources;

use Illuminate\Contracts\View\Factory;
use InvalidArgumentException;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Project;
use Ohwhatnow\Quine\Support\AppFiles;
use Ohwhatnow\Quine\Support\Matches;

/**
 * Any app class to the views it renders with view('name'): controllers,
 * Livewire components, mailables alike. The view name is resolved through the
 * view finder to the template file.
 */
final class RendersSource implements Source
{
    public function __construct(private readonly Factory $views) {}

    public function collect(Project $project, Graph $graph): void
    {
        foreach (AppFiles::under($project) as ['class' => $class, 'file' => $file, 'path' => $path]) {
            foreach (Matches::in('/view\(\s*[\'"]([\w.\-]+)[\'"]/', $file->getContents()) as [$view, $line]) {
                $graph->edge($class, $this->viewPath($view, $project), 'renders', $view, "$path:$line");
            }
        }
    }

    private function viewPath(string $name, Project $project): string
    {
        try {
            return $project->relative($this->views->getFinder()->find($name));
        } catch (InvalidArgumentException) {
            return "view:$name (missing)";
        }
    }
}
