<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Illuminate\Filesystem\Filesystem;
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
     * What to say about an edit to this file, in four groups: the callers of
     * a method the edit removed or changed, gaps (a reached template no test
     * renders, no test covering the file), hidden edges that live outside
     * the file, and quieter notes. Empty when the graph does not know the
     * file.
     *
     * @return array{callers: list<string>, gaps: list<string>, hidden: list<string>, notes: list<string>}
     */
    public function digest(Change $change): array
    {
        $relativePath = (string) $change->path;
        $node = $this->node($relativePath);

        if ($node === null || ! $change->touchesCode()) {
            return ['callers' => [], 'gaps' => [], 'hidden' => [], 'notes' => []];
        }

        $template = str_ends_with($node, '.blade.php');
        ['untested' => $untested, 'tested' => $tested] = $this->templates($node, $template, $change);
        ['gap' => $coverageGap, 'note' => $coverageNote] = $this->coverageOf($relativePath);

        return [
            'callers' => $template ? [] : $this->callers($node, $relativePath, $change),
            'gaps' => [
                ...array_map(fn (string $path) => "reaches $path: no test renders this", $untested),
                ...($coverageGap === null ? [] : [$coverageGap]),
            ],
            'hidden' => $template ? [] : $this->hiddenEdges($node, $relativePath, $change),
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
     * Who calls a method the edit removed or changed, by name, in the classes
     * that reference the edited one and the tests that cover it. A removal
     * with no caller is said out loud, because that is the grep the reader
     * would run next; a changed signature nobody calls is nothing to say.
     *
     * @return list<string>
     */
    private function callers(string $node, string $relativePath, Change $change): array
    {
        $lines = [];

        $added = $change->addedMethods();

        foreach ([['removed', $change->removedMethods()], ['signature changed', $change->changedMethods()]] as [$what, $methods]) {
            foreach ($methods as $method) {
                $label = class_basename($node)."::$method() $what";

                // A static that became a scope still answers the old call through the query builder.
                if ($what === 'removed' && in_array('scope'.ucfirst($method), $added, true)) {
                    $label = class_basename($node)."::$method() is now a scope (static callers still resolve through the builder)";
                }

                // With symbol edges for the class, the graph is the answer; the name match is for a graph without them.
                if ($this->hasSymbolEdges($node)) {
                    $found = $this->consumers($node, $method);

                    if ($found !== []) {
                        $lines[] = "$label; called from ".implode(', ', $found);
                    } elseif ($what === 'removed') {
                        $lines[] = "$label; no caller found";
                    }

                    continue;
                }

                $found = $this->callSites($node, $relativePath, $method);

                if ($found !== []) {
                    $lines[] = "$label; called by name from ".implode(', ', $found).' (name match, heuristic)';
                } elseif ($what === 'removed') {
                    $lines[] = "$label; no caller found by name match";
                }
            }
        }

        return $lines;
    }

    /**
     * Whether any template has symbol edges, which is only so when Bladestan
     * was installed at the last quine:update.
     */
    private function hasTemplateSymbolEdges(): bool
    {
        foreach ($this->graph->edges as $edge) {
            if (in_array($edge['kind'], ['calls', 'fetches'], true) && str_ends_with($edge['from'], '.blade.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the symbol index knows this class at all: any edge pointing at one of its members.
     */
    private function hasSymbolEdges(string $node): bool
    {
        foreach ($this->graph->edges as $edge) {
            if (str_starts_with($edge['to'], "$node::")) {
                return true;
            }
        }

        return false;
    }

    /**
     * path:line of every resolved consumer of the member, any kind of edge
     * (a removed relation's consumers are fetches), by path then line.
     *
     * @return list<string>
     */
    private function consumers(string $node, string $method): array
    {
        $sites = [];

        foreach ($this->graph->edgesTo("$node::$method") as $edge) {
            if ($edge['at'] !== null) {
                $sites[$edge['at']] = true;
            }
        }

        $sites = array_keys($sites);

        usort($sites, fn (string $a, string $b) => [Str::beforeLast($a, ':'), (int) Str::afterLast($a, ':')] <=> [Str::beforeLast($b, ':'), (int) Str::afterLast($b, ':')]);

        return $sites;
    }

    /**
     * path:line of every call of the method outside its own file, looked for
     * only where the graph says to look: the files of the classes with a uses
     * edge to the node, and the test files that cover the edited file.
     *
     * @return list<string>
     */
    private function callSites(string $node, string $relativePath, string $method): array
    {
        $candidates = [];

        foreach ($this->graph->edgesTo($node, 'uses') as $edge) {
            $candidates[] = Str::beforeLast((string) $edge['at'], ':');
        }

        foreach ($this->graph->coverage[$relativePath] ?? [] as $test) {
            if (is_string($test)) {
                $candidates[] = $test;
            }
        }

        $files = new Filesystem;
        $found = [];

        foreach (array_unique($candidates) as $path) {
            $absolute = $this->project->absolute($path);

            if ($path === $relativePath || $path === '' || ! $files->isFile($absolute)) {
                continue;
            }

            foreach (preg_split('/\R/', $files->get($absolute)) ?: [] as $index => $line) {
                if (preg_match('/\b'.preg_quote($method, '/').'\s*\(/', $line) === 1 && preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $line) !== 1) {
                    $found[] = $path.':'.($index + 1);
                }
            }
        }

        return $found;
    }

    /**
     * Framework-registered edges whose other end lives outside the edited
     * file and which the edit touches: an observer when a changed line names
     * it or its event, a policy when a changed line names it, a listener
     * chain when a changed line names the event or dispatches it. A booted()
     * closure in the model being edited is in plain sight; an edge the edit
     * never goes near is a description of the file, not of the change.
     *
     * @return list<string>
     */
    private function hiddenEdges(string $node, string $relativePath, Change $change): array
    {
        $lines = [];

        foreach ($this->graph->edgesFrom($node, 'model-event') as $edge) {
            if (! str_starts_with($edge['to'], "closure $relativePath:") && ($change->mentions($edge['label']) || $change->mentions(class_basename(Str::before($edge['to'], '@'))))) {
                $lines[] = "on {$edge['label']} -> {$edge['to']}";
            }
        }

        foreach ($this->graph->edgesFrom($node, 'policy') as $edge) {
            if ($change->mentions(class_basename($edge['to']))) {
                $lines[] = "policy -> {$edge['to']}";
            }
        }

        // There is no model-to-event edge: the use statement is the hop.
        foreach ($this->graph->edgesFrom($node, 'uses') as $reference) {
            foreach ($this->graph->edgesFrom($reference['to'], 'event') as $edge) {
                if ($change->mentions(class_basename($edge['from'])) || $change->mentions('dispatch')) {
                    $lines[] = "dispatches {$edge['from']} -> {$edge['to']} ({$edge['label']})";
                }
            }
        }

        return $lines;
    }

    /**
     * From a class: the templates it renders or is embedded in, always, since
     * any change to the class is a change to what they show; plus the
     * templates its referrers reach, only when one of them names a method the
     * edit declared, removed or changed (a name match, like the callers). Then
     * what those templates include. From a template: what includes it and
     * what it includes. One hop each way.
     *
     * @return array{untested: list<string>, tested: list<string>}
     */
    private function templates(string $node, bool $template, Change $change): array
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
            $found = $this->templatesOf($node);

            // With template symbol edges in the graph, a template is reached when it reads a
            // member the edit removed or changed; without them, when its text names one.
            if ($this->hasTemplateSymbolEdges()) {
                foreach ([...$change->removedMethods(), ...$change->changedMethods()] as $method) {
                    foreach ($this->graph->edgesTo("$node::$method") as $edge) {
                        if (str_ends_with($edge['from'], '.blade.php')) {
                            $found[] = $edge['from'];
                        }
                    }
                }
            } elseif (($methods = [...$change->addedMethods(), ...$change->removedMethods(), ...$change->changedMethods()]) !== []) {
                foreach ($this->graph->edgesTo($node, 'uses') as $edge) {
                    foreach ($this->templatesOf($edge['from']) as $path) {
                        if ($this->templateMentions($path, $methods)) {
                            $found[] = $path;
                        }
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
     * The templates a class renders or is embedded in.
     *
     * @return list<string>
     */
    private function templatesOf(string $class): array
    {
        $found = [];

        foreach ($this->graph->edgesFrom($class, 'renders') as $edge) {
            $found[] = $edge['to'];
        }

        foreach (['livewire', 'component'] as $kind) {
            foreach ($this->graph->edgesTo($class, $kind) as $edge) {
                $found[] = $edge['from'];
            }
        }

        return $found;
    }

    /**
     * Whether the template's source names any of the methods, as a word.
     *
     * @param  list<string>  $methods
     */
    private function templateMentions(string $path, array $methods): bool
    {
        $files = new Filesystem;
        $absolute = $this->project->absolute($path);

        if (! $files->isFile($absolute)) {
            return false;
        }

        $source = $files->get($absolute);

        foreach ($methods as $method) {
            if (preg_match('/\b'.preg_quote($method, '/').'\b/', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tests covering the file, as a gap when there are none and as a
     * count plus the command that runs them when there are: nobody reads
     * twenty-five file names, but pest --tia runs exactly those; a single one
     * is named, because that is the test the reader runs next. A stale cache
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

        $tests = $this->tests($relativePath);
        $count = count($tests);
        $note = $warning === null ? [] : [$warning];

        if ($count === 0) {
            return ['gap' => 'no test covers this file', 'note' => $note];
        }

        if ($count === 1) {
            return ['gap' => null, 'note' => [...$note, "1 test file covers this file: {$tests[0]} (vendor/bin/pest --tia runs it)"]];
        }

        return ['gap' => null, 'note' => [...$note, "$count test files cover this file: vendor/bin/pest --tia runs them"]];
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
