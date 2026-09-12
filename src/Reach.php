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
    /**
     * Endpoint lines the hook prints per edited method before pointing at quine:ask.
     */
    private const int CAP = 10;

    public function __construct(private Graph $graph, private Project $project) {}

    /**
     * What to say about an edit to this file, in four groups: the callers of
     * a method the edit removed or changed, gaps (a reached template no test
     * renders, no test covering the file), hidden edges that live outside
     * the file, and quieter notes. Empty when the graph does not know the
     * file.
     *
     * @return array{callers: list<string>, reach: list<string>, gaps: list<string>, hidden: list<string>, notes: list<string>}
     */
    public function digest(Change $change): array
    {
        $relativePath = (string) $change->path;
        $node = $this->node($relativePath);

        if ($node === null || ! $change->touchesCode()) {
            return ['callers' => [], 'reach' => [], 'gaps' => [], 'hidden' => [], 'notes' => []];
        }

        $template = str_ends_with($node, '.blade.php');
        ['untested' => $untested, 'tested' => $tested] = $this->templates($node, $template, $change);
        ['gap' => $coverageGap, 'note' => $coverageNote] = $this->coverageOf($relativePath);
        ['lines' => $reach, 'templates' => $walked] = $template ? ['lines' => [], 'templates' => []] : $this->reachOf($node, $change);

        // A template the walk reached has its line, trail and coverage there; not twice.
        $untested = array_values(array_diff($untested, $walked));
        $tested = array_values(array_diff($tested, $walked));

        return [
            'callers' => $template ? [] : $this->callers($node, $relativePath, $change),
            'reach' => $reach,
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
     * The walk lines for every method the edit removed, changed or edited the
     * body of, capped for the hook, plus the templates they reached.
     *
     * @return array{lines: list<string>, templates: list<string>}
     */
    private function reachOf(string $node, Change $change): array
    {
        $lines = [];
        $templates = [];

        foreach (array_unique([...$change->removedMethods(), ...$change->changedMethods(), ...$change->editedMethods()]) as $method) {
            ['lines' => $found, 'templates' => $reached] = $this->reachLines($this->memberNode($node, $method), self::CAP);
            $lines = [...$lines, ...$found];
            $templates = [...$templates, ...$reached];
        }

        return ['lines' => $lines, 'templates' => $templates];
    }

    /**
     * The member node a declared method is consumed as. A scope is declared
     * scopePublished and called published; an accessor is declared
     * getTitleLabelAttribute, or titleLabel() returning Attribute, and read
     * as title_label; the index knows the consumed name. A declared name that
     * has consumers of its own is a plain method and stays as it is.
     */
    private function memberNode(string $class, string $method): string
    {
        if ($this->consumerEdges("$class::$method") !== []) {
            return "$class::$method";
        }

        $consumed = match (true) {
            preg_match('/^scope([A-Z]\w*)$/', $method, $match) === 1 => lcfirst($match[1]),
            preg_match('/^[gs]et([A-Z]\w*)Attribute$/', $method, $match) === 1 => Str::snake($match[1]),
            default => Str::snake($method),
        };

        return $this->consumerEdges("$class::$consumed") === [] ? "$class::$method" : "$class::$consumed";
    }

    /**
     * The walk from one member as printed lines: endpoints (capped when a
     * cap is given, then a pointer at quine:ask), frontiers, the depth stop.
     *
     * @return array{lines: list<string>, templates: list<string>}
     */
    public function reachLines(string $member, ?int $cap = null): array
    {
        $walk = $this->walk($member);
        $lines = [];
        $templates = [];
        $byTrail = [];
        $routes = [];

        foreach ($walk['endpoints'] as $endpoint) {
            // The routed actions one trail reaches on one class share a line.
            if (str_starts_with($endpoint['kind'], 'route ')) {
                $routes[implode(' -> ', $endpoint['trail']).' '.Str::before($endpoint['node'], '::')][] = $endpoint;

                continue;
            }

            if ($endpoint['kind'] !== 'template') {
                $lines[] = $this->endpointLine($endpoint);

                continue;
            }

            // The templates one trail reaches share a line, each at the line that reads the member, with what renders it.
            $templates[] = $endpoint['node'];
            $byTrail[implode(' -> ', $endpoint['trail'])][] = $endpoint;
        }

        foreach ($routes as $group) {
            $lines[] = count($group) === 1 ? $this->endpointLine($group[0]) : $this->routesLine($group);
        }

        foreach ($byTrail as $trail => $reached) {
            $named = array_map(fn (array $endpoint) => "{$endpoint['at']} (".$this->templateCoverage($endpoint['node']).')', $reached);
            $lines[] = 'reaches '.implode(', ', $named).' via '.implode(' -> ', array_map($this->short(...), explode(' -> ', $trail))).'; whether a test exercises your change is yours to check';
        }

        // The cap is on printed lines, after routes and templates have shared theirs.
        if ($cap !== null && count($lines) > $cap) {
            $more = count($lines) - $cap;
            $lines = [...array_slice($lines, 0, $cap), "and $more more: quine:ask ".$this->short($member).' lists them'];
        }

        foreach ($walk['frontiers'] as $frontier) {
            $lines[] = 'reached '.$this->short($frontier).', nothing found that uses it';
        }

        if ($walk['stopped']) {
            $lines[] = "walk stopped at depth {$this->depth()} below ".$this->short($member).' (quine.reach.depth)';
        }

        return ['lines' => $lines, 'templates' => $templates];
    }

    /**
     * Where a member's consumers lead, member to member, through classes that
     * only pass the call on, until something the outside world touches: a
     * route, a template, a Livewire component, a listener, the schedule, a
     * job, a mailable, a notification, an MCP tool. Tests are never
     * endpoints and never walked through; the member's own class is never an
     * endpoint. Bounded by a visited set and the configured depth.
     *
     * @return array{endpoints: list<array{node: string, kind: string, trail: list<string>, at: string, coverage: string}>, frontiers: list<string>, stopped: bool}
     */
    public function walk(string $member): array
    {
        $own = Str::before($member, '::');
        $queue = [[$member, [$member], 0, null]];
        $visited = [$member => true];
        $endpoints = [];
        $frontiers = [];
        $stopped = false;

        while ($queue !== []) {
            [$current, $trail, $depth, $site] = array_shift($queue);

            foreach ($this->consumerEdges($current) as $edge) {
                $from = $edge['from'];

                if (isset($visited[$from]) || $this->isTestPath($from)) {
                    continue;
                }

                $visited[$from] = true;
                // Where the edited member itself is consumed: the first hop's site.
                $at = $site ?? (string) $edge['at'];

                if (str_ends_with($from, '.blade.php')) {
                    $endpoints[] = ['node' => $from, 'kind' => 'template', 'trail' => $trail, 'at' => $at, 'coverage' => $this->templateCoverage($from)];

                    continue;
                }

                // Class-level code (a property default): nothing to follow.
                if (! str_contains($from, '::')) {
                    continue;
                }

                $class = Str::before($from, '::');
                $kind = $class === $own ? null : $this->endpointKind($class, Str::after($from, '::'));

                if ($kind !== null) {
                    $endpoints[] = ['node' => $from, 'kind' => $kind, 'trail' => $trail, 'at' => $at, 'coverage' => $this->fileCoverage(Str::beforeLast((string) $edge['at'], ':'))];

                    continue;
                }

                // A method nobody calls (a resource's toArray, a job's handle, a mailable's
                // content) runs for whoever constructs, collects or dispatches its class: walk on from there.
                $nexts = $this->consumerEdges($from) === [] ? $this->entriesOf($class, $visited) : [$from];

                if ($nexts === []) {
                    $frontiers[] = $from;

                    continue;
                }

                if ($depth + 1 >= $this->depth()) {
                    $stopped = true;

                    continue;
                }

                foreach ($nexts as $next) {
                    $visited[$next] = true;
                    $queue[] = [$next, [...$trail, $from, ...($next === $from ? [] : [$next])], $depth + 1, $at];
                }
            }
        }

        return ['endpoints' => $endpoints, 'frontiers' => $frontiers, 'stopped' => $stopped];
    }

    /**
     * The constructor, collection or dispatch members of the class that
     * something consumes: the ways in to a class whose methods the framework
     * calls.
     *
     * @param  array<string, true>  $visited
     * @return list<string>
     */
    private function entriesOf(string $class, array $visited): array
    {
        $entries = [];

        foreach ($this->graph->edges as $edge) {
            if (! in_array($edge['kind'], ['calls', 'fetches'], true) || ! str_starts_with($edge['to'], "$class::")) {
                continue;
            }

            $member = Str::after($edge['to'], "$class::");

            if ((in_array($member, ['__construct', 'collection', 'make'], true) || str_starts_with($member, 'dispatch')) && ! isset($visited[$edge['to']])) {
                $entries[$edge['to']] = true;
            }
        }

        return array_keys($entries);
    }

    /**
     * What makes the member the end of a walk, or null for a class the walk
     * goes through. A route counts only for its own action; the rest are
     * facts about the class.
     */
    private function endpointKind(string $class, string $method): ?string
    {
        $routes = $this->graph->edgesTo($class, 'route');

        // A route to a class rather than an action ("page": a Livewire full-page component)
        // makes every public method of it an entry point.
        foreach ($routes as $edge) {
            $action = Str::before($edge['label'], ' ');

            if ($action === $method || $action === 'page') {
                return ($action === 'page' ? 'page ' : 'route ').Str::after(Str::beforeLast($edge['from'], ' ['), 'route ');
            }
        }

        // A method of a routed class that no route names is a helper the walk goes through.
        if ($routes !== []) {
            return null;
        }

        if ($this->graph->edgesTo($class, 'livewire') !== []) {
            return 'Livewire component';
        }

        if ($this->graph->edgesTo($class, 'component') !== []) {
            return 'Blade component';
        }

        foreach ($this->graph->edgesFrom($class, 'renders') as $edge) {
            return 'renders '.$edge['to'];
        }

        foreach ($this->graph->edges as $edge) {
            if ($edge['kind'] === 'event' && Str::before($edge['to'], '@') === $class) {
                return $edge['label'];
            }
        }

        foreach ($this->graph->edgesTo($class, 'schedule') as $edge) {
            return 'scheduled '.$edge['label'];
        }

        return match (true) {
            str_contains($class, '\\Jobs\\') => 'job, by namespace',
            str_contains($class, '\\Mail\\') => 'mailable, by namespace',
            str_contains($class, '\\Notifications\\') => 'notification, by namespace',
            str_contains($class, '\\Mcp\\Servers\\') => 'MCP server, by namespace',
            str_contains($class, '\\Mcp\\') => 'MCP tool, by namespace',
            default => null,
        };
    }

    /**
     * @param  array{node: string, kind: string, trail: list<string>, at: string, coverage: string}  $endpoint
     */
    private function endpointLine(array $endpoint): string
    {
        // The constructor bridge reads as what it is: the class being made, not a call to __construct.
        $hops = array_map(
            fn (string $hop) => str_ends_with($hop, '::__construct') ? 'new '.class_basename(Str::before($hop, '::')) : $this->short($hop),
            [...$endpoint['trail'], $endpoint['node']],
        );

        $what = match (true) {
            str_starts_with($endpoint['kind'], 'route ') => $endpoint['kind'].' ('.$this->short($endpoint['node']).')',
            // A page route mounts the component; the method is a Livewire action on it, not what GET runs.
            str_starts_with($endpoint['kind'], 'page ') => 'route '.Str::after($endpoint['kind'], 'page ').' ('.class_basename(Str::before($endpoint['node'], '::')).' component, '.Str::after($endpoint['node'], '::').')',
            default => Str::before($endpoint['node'], '::').' ('.$endpoint['kind'].')',
        };

        return "reaches $what via ".implode(' -> ', $hops)." (at {$endpoint['at']}); {$endpoint['coverage']}";
    }

    /**
     * Several routed actions of one class, reached by one trail, as one line.
     *
     * @param  non-empty-list<array{node: string, kind: string, trail: list<string>, at: string, coverage: string}>  $group
     */
    private function routesLine(array $group): string
    {
        $first = $group[0];
        $hops = array_map(
            fn (string $hop) => str_ends_with($hop, '::__construct') ? 'new '.class_basename(Str::before($hop, '::')) : $this->short($hop),
            $first['trail'],
        );
        $routes = implode(', ', array_map(fn (array $endpoint) => Str::after($endpoint['kind'], 'route '), $group));
        $actions = implode(', ', array_map(fn (array $endpoint) => Str::after($endpoint['node'], '::'), $group));

        return "reaches routes $routes (".class_basename(Str::before($first['node'], '::'))."::$actions) via ".implode(' -> ', $hops)." (at {$first['at']}); {$first['coverage']}";
    }

    /**
     * The calls and fetches edges into a node.
     *
     * @return list<array{from: string, to: string, kind: string, label: string, at: ?string}>
     */
    private function consumerEdges(string $node): array
    {
        return array_values(array_filter($this->graph->edgesTo($node), fn (array $edge) => in_array($edge['kind'], ['calls', 'fetches'], true)));
    }

    private function isTestPath(string $node): bool
    {
        return str_ends_with($node, '.php') && ! str_ends_with($node, '.blade.php');
    }

    /**
     * Class::member without the namespace.
     */
    private function short(string $member): string
    {
        return str_contains($member, '::') ? class_basename(Str::before($member, '::')).'::'.Str::after($member, '::') : class_basename($member);
    }

    private function depth(): int
    {
        return max(1, (int) config('quine.reach.depth', 6));
    }

    /**
     * What renders the template: up to three test files by name, or nothing.
     */
    private function templateCoverage(string $path): string
    {
        $tests = $this->tests($path);

        if ($tests === []) {
            return 'no test renders this';
        }

        $more = count($tests) - 3;

        return implode(', ', array_slice($tests, 0, 3)).($more > 0 ? " +$more" : '').(count($tests) === 1 ? ' renders it' : ' render it');
    }

    private function fileCoverage(string $path): string
    {
        $tests = $this->tests($path);

        return match (count($tests)) {
            0 => "no test covers $path",
            1 => "1 test file covers $path: {$tests[0]}",
            default => count($tests)." test files cover $path",
        };
    }

    /**
     * The graph node the edited path is: a template by its path, a model by
     * its recorded file, any other app class by PSR-4 from its path. A file
     * the graph never mentions is nothing to say anything about.
     */
    /**
     * Whether the graph has a node for this file: an app class or a template
     * it saw when it was built. A file it could never have (a route file, a
     * migration) is not "unknown", just not a node.
     *
     * @return bool|null null when the file is not the kind that gets a node
     */
    public function knows(string $relativePath): ?bool
    {
        $template = str_ends_with($relativePath, '.blade.php');
        $absolute = $this->project->absolute($relativePath);
        $underApp = str_starts_with($absolute, rtrim($this->project->appPath, '/').'/') && str_ends_with($relativePath, '.php');

        if (! $template && ! $underApp) {
            return null;
        }

        return $this->node($relativePath) !== null;
    }

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
            if ($edge['from'] === $node || $edge['to'] === $node || str_starts_with($edge['from'], "$node::") || str_starts_with($edge['to'], "$node::")) {
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
                    $found = $this->consumers($node, Str::after($this->memberNode($node, $method), "$node::"));

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
                    foreach ($this->graph->edgesTo($this->memberNode($node, $method)) as $edge) {
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
