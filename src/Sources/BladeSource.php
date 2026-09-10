<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Illuminate\Contracts\View\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use Ohffs\Quine\Support\ComponentResolver;
use Ohffs\Quine\Support\Matches;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The Blade side: what every template under the view paths pulls in
 * (components, livewire tags, includes, gate checks) and every property
 * chain it reads. The reads are what the nullable recipe hunts through.
 */
final class BladeSource implements Source
{
    public function __construct(
        private readonly ComponentResolver $components,
        private readonly Factory $views,
    ) {}

    public function collect(Project $project, Graph $graph): void
    {
        foreach ($this->templates($project) as $file) {
            $path = $project->relative($file->getPathname());
            $source = $file->getContents();

            foreach (Matches::in('/<x-([\w.:-]+)/', $source) as [$name, $line]) {
                $target = $this->components->resolve($name);
                $graph->edge($path, $target === '' ? "component:$name" : $project->relative($target), 'component', "<x-$name>", "$path:$line");
            }

            foreach (Matches::in('/<flux:([\w.:-]+)/', $source) as [$name, $line]) {
                $graph->edge($path, "flux:$name", 'flux', "<flux:$name>", "$path:$line");
            }

            foreach (Matches::in('/<livewire:([\w.\-]+)/', $source) as [$name, $line]) {
                $this->livewire($graph, $project, $path, $name, "<livewire:$name>", $line);
            }

            foreach (Matches::in('/@livewire\(\s*[\'"]([\w.\-]+)/', $source) as [$name, $line]) {
                $this->livewire($graph, $project, $path, $name, "@livewire('$name')", $line);
            }

            foreach (Matches::in('/@(?:include|includeIf|includeWhen|includeUnless|includeFirst|extends|each)\(\s*[\'"]([\w.\-]+)/', $source) as [$name, $line]) {
                $graph->edge($path, $this->viewPath($name, $project), 'include', $name, "$path:$line");
            }

            foreach (Matches::in('/@(?:can|cannot|canany|elsecan)\(\s*[\'"](\w+)/', $source) as [$name, $line]) {
                $graph->edge($path, "gate:$name", 'gate', "@can('$name')", "$path:$line");
            }

            foreach (Matches::in('/(\$\w+(?:\??->\w+)+)/', $source) as [$chain, $line]) {
                $graph->edge($path, $chain, 'reads', str_contains($chain, '?->') ? 'null-safe' : 'unguarded', "$path:$line");
            }
        }
    }

    /**
     * <livewire:comment-box> is App\Livewire\CommentBox by convention only, so the
     * edge is labelled a heuristic and only emitted when that class exists.
     */
    private function livewire(Graph $graph, Project $project, string $path, string $name, string $tag, int $line): void
    {
        $class = $project->namespace.'Livewire\\'.implode('\\', array_map(fn (string $part) => Str::studly($part), explode('.', $name)));

        if (! class_exists($class)) {
            return;
        }

        $graph->edge($path, $class, 'livewire', "$tag (heuristic: naming convention)", "$path:$line");
    }

    private function viewPath(string $name, Project $project): string
    {
        try {
            return $project->relative($this->views->getFinder()->find($name));
        } catch (InvalidArgumentException) {
            return "view:$name (missing)";
        }
    }

    /**
     * Every .blade.php under the configured view paths, skipping published vendor views.
     *
     * @return list<SplFileInfo>
     */
    private function templates(Project $project): array
    {
        $files = new Filesystem;
        $templates = [];

        foreach ($project->viewPaths as $viewPath) {
            if (! $files->isDirectory($viewPath)) {
                continue;
            }

            foreach ($files->allFiles($viewPath) as $file) {
                if (str_ends_with($file->getFilename(), '.blade.php') && ! str_contains(str_replace('\\', '/', $file->getPathname()), '/vendor/')) {
                    $templates[] = $file;
                }
            }
        }

        return $templates;
    }
}
