<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Illuminate\Support\Str;
use Ohffs\Quine\Console\Summary;
use Ohffs\Quine\Support\AppFiles;

/**
 * What an edit to one file can reach, said the way a colleague would: the
 * framework-registered edges hanging off it, the templates a change there can
 * arrive at with the tests that render each, and the tests covering the file.
 * Plain strings, no console styling, because the editor hook wraps raw stdout.
 */
final readonly class Reach
{
    public function __construct(private Graph $graph, private Project $project) {}

    /**
     * What to say about an edit to this file, in three groups: gaps (a
     * reached template no test renders, no test covering the file), hidden
     * edges that live outside the file, and quieter notes. Empty when the
     * graph does not know the file.
     *
     * @return array{gaps: list<string>, hidden: list<string>, notes: list<string>}
     */
    public function digest(string $relativePath): array
    {
        $node = $this->node($relativePath);

        if ($node === null) {
            return ['gaps' => [], 'hidden' => [], 'notes' => []];
        }

        $template = str_ends_with($node, '.blade.php');
        ['untested' => $untested, 'tested' => $tested] = $this->templates($node, $template);
        ['gap' => $coverageGap, 'note' => $coverageNote] = $this->coverageOf($relativePath);

        return [
            'gaps' => [
                ...array_map(fn (string $path) => "reaches $path: no test renders this", $untested),
                ...($coverageGap === null ? [] : [$coverageGap]),
            ],
            'hidden' => $template ? [] : $this->hiddenEdges($node, $relativePath),
            'notes' => [
                ...($tested === [] ? [] : ['templates reached: '.implode(', ', $tested).' (a test renders each; whether it exercises your change is yours to check)']),
                ...$coverageNote,
            ],
        ];
    }

    /**
     * The graph node the edited path is: a template by its path, a model by
     * its recorded file, any other app class by PSR-4 from its path. A file
     * the graph never mentions is nothing to say anything about.
     */
    private function node(string $relativePath): ?string
    {
        if (str_ends_with($relativePath, '.blade.php')) {
            return $this->known($relativePath) ? $relativePath : null;
        }

        foreach ($this->graph->models as $class => $model) {
            if (is_array($model) && ($model['file'] ?? null) === $relativePath) {
                return $class;
            }
        }

        foreach (AppFiles::under($this->project) as ['class' => $class, 'path' => $path]) {
            if ($path === $relativePath) {
                return $this->known($class) ? $class : null;
            }
        }

        return null;
    }

    private function known(string $node): bool
    {
        if (array_key_exists($node, $this->graph->models)) {
            return true;
        }

        foreach ($this->graph->edges as $edge) {
            if ($edge['from'] === $node || $edge['to'] === $node) {
                return true;
            }
        }

        return false;
    }

    /**
     * Framework-registered edges whose other end lives outside the edited
     * file. A booted() closure in the model being edited is in plain sight;
     * an observer, policy or listener registered elsewhere is not.
     *
     * @return list<string>
     */
    private function hiddenEdges(string $node, string $relativePath): array
    {
        $lines = [];

        foreach ($this->graph->edgesFrom($node, 'model-event') as $edge) {
            if (! str_starts_with($edge['to'], "closure $relativePath:")) {
                $lines[] = "on {$edge['label']} -> {$edge['to']}";
            }
        }

        foreach ($this->graph->edgesFrom($node, 'policy') as $edge) {
            $lines[] = "policy -> {$edge['to']}";
        }

        // There is no model-to-event edge: the use statement is the hop.
        foreach ($this->graph->edgesFrom($node, 'uses') as $reference) {
            foreach ($this->graph->edgesFrom($reference['to'], 'event') as $edge) {
                $lines[] = "dispatches {$edge['from']} -> {$edge['to']} ({$edge['label']})";
            }
        }

        return $lines;
    }

    /**
     * From a class: the templates rendered by, or embedding, the class and its
     * direct referrers, plus what those templates include. From a template:
     * what includes it and what it includes. One hop each way, so the list on
     * a real app is bounded by direct references, not by the whole graph.
     *
     * @return array{untested: list<string>, tested: list<string>}
     */
    private function templates(string $node, bool $template): array
    {
        $found = [];

        if ($template) {
            foreach ($this->graph->edgesTo($node, 'include') as $edge) {
                $found[] = $edge['from'];
            }

            foreach ($this->graph->edgesFrom($node, 'include') as $edge) {
                $found[] = $edge['to'];
            }
        } else {
            $classes = [$node, ...array_map(fn (array $edge) => $edge['from'], $this->graph->edgesTo($node, 'uses'))];

            foreach ($classes as $class) {
                foreach ($this->graph->edgesFrom($class, 'renders') as $edge) {
                    $found[] = $edge['to'];
                }

                foreach (['livewire', 'component'] as $kind) {
                    foreach ($this->graph->edgesTo($class, $kind) as $edge) {
                        $found[] = $edge['from'];
                    }
                }
            }

            foreach ($found as $path) {
                foreach ($this->graph->edgesFrom($path, 'include') as $edge) {
                    $found[] = $edge['to'];
                }
            }
        }

        $untested = [];
        $tested = [];

        foreach (array_unique($found) as $path) {
            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }

            if ($this->tests($path) === []) {
                $untested[] = $path;
            } else {
                $tested[] = $path;
            }
        }

        return ['untested' => $untested, 'tested' => $tested];
    }

    /**
     * The tests covering the file, as a gap when there are none and as a
     * count plus the command that runs them when there are: nobody reads
     * twenty-five file names, but pest --tia runs exactly those. A stale cache
     * is still worth reading, so its warning comes first; a missing cache has
     * nothing to say about the file.
     *
     * @return array{gap: ?string, note: list<string>}
     */
    private function coverageOf(string $relativePath): array
    {
        $warning = Summary::coverageWarning($this->graph);

        if (($this->graph->meta['coverage'] ?? 'unavailable') === 'unavailable') {
            return ['gap' => null, 'note' => [(string) $warning]];
        }

        $count = count($this->tests($relativePath));
        $note = $warning === null ? [] : [$warning];

        if ($count === 0) {
            return ['gap' => 'no test covers this file', 'note' => $note];
        }

        return ['gap' => null, 'note' => [...$note, "$count ".Str::plural('test file', $count).' cover'.($count === 1 ? 's' : '').' this file: vendor/bin/pest --tia runs '.($count === 1 ? 'it' : 'them')]];
    }

    /**
     * @return list<string>
     */
    private function tests(string $path): array
    {
        $covering = $this->graph->coverage[$path] ?? null;

        if (! is_array($covering)) {
            return [];
        }

        return array_values(array_map('basename', array_filter($covering, 'is_string')));
    }
}
