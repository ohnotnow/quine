<?php

declare(strict_types=1);

namespace Ohffs\Quine\Console;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Ohffs\Quine\Change;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Recipes\Registry;

/**
 * The readable end of quine:update: what a developer running it by hand sees,
 * while the JSON stays for the machines.
 */
final class Summary
{
    private readonly Factory $components;

    public function __construct(
        private readonly Command $command,
        private readonly Graph $graph,
    ) {
        $this->components = new Factory($command->getOutput());
    }

    public function models(): void
    {
        $this->components->twoColumnDetail('<fg=yellow>MODELS</>', 'relations, hooks, accessors');

        foreach ($this->graph->models as $class => $model) {
            if (! is_array($model)) {
                continue;
            }

            $this->command->line("  <fg=cyan>$class</> (".$this->string($model['table'] ?? '').')');

            foreach ($this->rows($model['relations'] ?? null) as $name => $relation) {
                $foreignKey = $this->string($relation['foreign_key'] ?? '');
                $extra = match (true) {
                    isset($relation['nullable']) => $relation['nullable'] === true ? "<fg=red>NULLABLE</> $foreignKey" : "not null $foreignKey",
                    isset($relation['pivot']) => 'via '.$this->string($relation['pivot']),
                    default => $foreignKey,
                };

                $this->command->line("    {$name}(): ".$this->string($relation['type'] ?? '').' -> '.class_basename($this->string($relation['related'] ?? '')).($extra === '' ? '' : "  $extra"));
            }

            foreach ($this->graph->edgesFrom($class, 'model-event') as $edge) {
                $this->command->line("    on <fg=magenta>{$edge['label']}</> -> {$edge['to']}");
            }

            $accessors = $this->strings($model['accessors'] ?? null);

            if ($accessors !== []) {
                $this->command->line('    accessors: '.implode(', ', $accessors));
            }

            $casts = [];

            foreach (is_array($model['casts'] ?? null) ? $model['casts'] : [] as $column => $cast) {
                $casts[] = "$column=".class_basename($this->string($cast));
            }

            if ($casts !== []) {
                $this->command->line('    casts: '.implode(', ', $casts));
            }
        }
    }

    public function hiddenEdges(): void
    {
        $this->command->newLine();
        $this->components->twoColumnDetail('<fg=yellow>RUNS WITHOUT BEING CALLED</>', 'events, gates, policies, schedule');

        foreach ($this->graph->edges as $edge) {
            if (in_array($edge['kind'], ['event', 'gate', 'policy', 'schedule'], true) && ! str_ends_with($edge['from'], '.blade.php')) {
                $this->command->line("  {$edge['from']} --{$edge['label']}--> {$edge['to']}");
            }
        }
    }

    public function pages(): void
    {
        $this->command->newLine();
        $this->components->twoColumnDetail('<fg=yellow>ROUTES</>', 'route, handler, template (tests rendering it)');
        $this->coverageState();

        foreach ($this->graph->edges as $route) {
            if ($route['kind'] !== 'route') {
                continue;
            }

            $this->command->line("  <fg=cyan>{$route['from']}</> -> ".class_basename($route['to']));

            foreach ($this->graph->edgesFrom($route['to'], 'renders') as $render) {
                $tests = $this->strings($this->graph->coverage[$render['to']] ?? null);
                $flag = $tests === [] ? '<fg=red>no test renders this</>' : count($tests).' test files';
                $this->command->line("      {$render['to']}  ($flag)");

                foreach ($this->graph->edgesFrom($render['to'], 'livewire') as $child) {
                    $this->command->line('        <livewire> '.class_basename($child['to']));
                }

                foreach ($this->graph->edgesFrom($render['to'], 'gate') as $gate) {
                    $this->command->line("        {$gate['label']}");
                }
            }
        }

        $this->symbols();
    }

    /**
     * How much of the member-level index the graph holds, from what SymbolsSource counted.
     */
    private function symbols(): void
    {
        $symbols = $this->graph->meta['symbols'] ?? null;

        if (! is_array($symbols)) {
            return;
        }

        $line = sprintf('  symbols: %d calls, %d fetches from %d files', $symbols['calls'] ?? 0, $symbols['fetches'] ?? 0, $symbols['files'] ?? 0);

        $unreadable = is_array($symbols['templates_unreadable'] ?? null) ? $symbols['templates_unreadable'] : [];
        $partial = is_array($symbols['templates_partial'] ?? null) ? $symbols['templates_partial'] : [];

        if (($symbols['bladestan'] ?? false) === true) {
            $line .= sprintf('; templates: %d indexed', $symbols['templates'] ?? 0);
            $line .= $partial === [] ? '' : sprintf(' (%d without composer data)', count($partial));
            $line .= $unreadable === [] ? '' : sprintf(', %d quine could not read', count($unreadable));
            $line .= $partial === [] && $unreadable === [] ? '' : ' (quine:update -v says which)';
        } else {
            $line .= '; templates: not indexed (tomasvotruba/bladestan is not installed)';
        }

        $this->command->line($line);

        // A template quine could not read is one whose reads it only knows by name matching; one
        // read without its composer data has every variable a composer adds untyped.
        if ($this->command->getOutput()->isVerbose()) {
            foreach ($unreadable as $path => $error) {
                $this->command->line("  could not read $path: $error");
            }

            foreach ($partial as $path => $error) {
                $this->command->line("  read $path without its view composers' data: $error");
            }
        }
    }

    public function nudges(Registry $recipes): void
    {
        $this->command->newLine();
        $this->components->twoColumnDetail('<fg=yellow>CHECK BEFORE EDITING</>', 'what the recipes found');
        $printed = false;

        foreach (array_keys($this->graph->models) as $class) {
            foreach ($recipes->nudges(Change::forNode($class), $this->graph) as $nudge) {
                $this->command->line("  $nudge");
                $printed = true;
            }
        }

        if (! $printed) {
            $this->command->line('  nothing to add');
        }
    }

    private function coverageState(): void
    {
        $warning = self::coverageWarning($this->graph);

        if ($warning !== null) {
            $this->command->line("  <fg=yellow>$warning</>");

            return;
        }

        $tests = [];

        foreach ($this->graph->coverage as $covering) {
            $tests = [...$tests, ...$this->strings($covering)];
        }

        $this->command->line('  tia cache is fresh: '.count(array_unique($tests)).' test files');
    }

    /**
     * Why the coverage overlay cannot be trusted right now, or null when it can.
     */
    /**
     * Whether test files exist that the coverage data has never seen. While
     * that is so, "no test covers X" is not a fact, only a gap in the data.
     */
    public static function coverageBehind(Graph $graph): bool
    {
        return array_filter(is_array($graph->meta['coverage_missing_tests'] ?? null) ? $graph->meta['coverage_missing_tests'] : [], 'is_string') !== [];
    }

    public static function coverageWarning(Graph $graph): ?string
    {
        if (($graph->meta['coverage'] ?? 'unavailable') === 'unavailable') {
            return 'no pest tia cache found: run vendor/bin/pest --tia';
        }

        $missing = array_values(array_filter(is_array($graph->meta['coverage_missing_tests'] ?? null) ? $graph->meta['coverage_missing_tests'] : [], 'is_string'));

        if ($missing !== []) {
            $count = count($missing);

            return "coverage data predates $count test file".($count === 1 ? '' : 's').' ('.implode(', ', array_map('basename', $missing)).'): vendor/bin/pest --tia refreshes it, one normal suite run, then only the tests your change touches';
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        $rows = [];

        foreach (is_array($value) ? $value : [] as $key => $row) {
            if (is_array($row)) {
                $rows[(string) $key] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
