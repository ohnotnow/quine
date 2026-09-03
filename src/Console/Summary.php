<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Console;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Ohwhatnow\Quine\Change;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Recipes\Registry;

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
        $this->components->twoColumnDetail('<fg=yellow>HIDDEN EDGES</>', 'events, gates, policies, schedule');

        foreach ($this->graph->edges as $edge) {
            if (in_array($edge['kind'], ['event', 'gate', 'policy', 'schedule'], true) && ! str_ends_with($edge['from'], '.blade.php')) {
                $this->command->line("  {$edge['from']} --{$edge['label']}--> {$edge['to']}");
            }
        }
    }

    public function pages(): void
    {
        $this->command->newLine();
        $this->components->twoColumnDetail('<fg=yellow>PAGES</>', 'route -> handler -> view (tests rendering it)');
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
    }

    public function nudges(Registry $recipes): void
    {
        $this->command->newLine();
        $this->components->twoColumnDetail('<fg=yellow>NUDGES</>', 'what to check before you edit');
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
        $state = $this->graph->meta['coverage'] ?? 'unavailable';

        if ($state === 'unavailable') {
            $this->command->line('  <fg=yellow>no pest tia cache found: run vendor/bin/pest --tia</>');

            return;
        }

        $missing = $this->strings($this->graph->meta['coverage_missing_tests'] ?? null);

        if ($missing !== []) {
            $this->command->line('  <fg=yellow>tia cache is stale: '.count($missing).' test files are not in it ('.implode(', ', array_map('basename', $missing)).'): re-run vendor/bin/pest --tia</>');

            return;
        }

        $tests = [];

        foreach ($this->graph->coverage as $covering) {
            $tests = [...$tests, ...$this->strings($covering)];
        }

        $this->command->line('  tia cache is fresh: '.count(array_unique($tests)).' test files');
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
