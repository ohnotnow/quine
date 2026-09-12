<?php

declare(strict_types=1);

namespace Ohffs\Quine\Recipes;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Ohffs\Quine\Change;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Nudge;
use Ohffs\Quine\Project;
use Ohffs\Quine\Support\AppFiles;
use Ohffs\Quine\Support\Describe;
use Ohffs\Quine\Support\Matches;
use Symfony\Component\Finder\SplFileInfo;

/**
 * A belongsTo whose foreign key is nullable: who still dereferences it as if
 * it were always set, and does any fixture ever produce the null state?
 *
 * @phpstan-type Target array{class: string, model: string, relation: string, related: string, table: string, column: string, file: string, nullable: ?bool}
 */
final class NullableBelongsTo implements Recipe
{
    public function __construct(private readonly Project $project) {}

    public function nudges(Change $change, Graph $graph): array
    {
        if ($change->path !== null && str_ends_with($change->path, '.blade.php')) {
            return $this->templateNudges($change, $graph);
        }

        $nudges = [];

        foreach ($this->targets($change, $graph) as $target) {
            $nudges = [
                ...$nudges,
                ...$this->migrationLines($target),
                ...$this->unguardedReads($target, $graph),
                ...$this->fixtureGap($target),
            ];
        }

        return $nudges;
    }

    /**
     * A template edit that reads through a nullable relation: the unguarded
     * reads in the template as it now is (the graph's copy predates the
     * edit), and whether any fixture produces the null case. The migration
     * line is left out; the template is where the hazard lands, not where
     * it was made.
     *
     * @return list<Nudge>
     */
    private function templateNudges(Change $change, Graph $graph): array
    {
        $path = (string) $change->path;
        $absolute = $this->project->absolute($path);
        $contents = is_file($absolute) ? (string) file_get_contents($absolute) : '';
        $nudges = [];

        foreach ($this->templateTargets($change, $graph) as $target) {
            $nudges = [...$nudges, ...array_column($this->regexReads($contents, $path, $target), 'nudge'), ...$this->fixtureGap($target)];
        }

        return $nudges;
    }

    /**
     * The nullable belongsTo relations a template edit reads through: a
     * changed line carries `$var->relation` with the variable named for the
     * model.
     *
     * @return list<Target>
     */
    private function templateTargets(Change $change, Graph $graph): array
    {
        $changed = implode("\n", [...$change->addedLines(), ...$change->removedLines()]);
        $targets = [];

        foreach ($this->nullableRelations($graph, array_keys($graph->models)) as $target) {
            foreach (Matches::in('/\$(\w+)\??->'.preg_quote($target['relation'], '/').'\b/', $changed) as [$variable]) {
                if ($this->variableMatches($variable, $target, null)) {
                    $targets[] = $target;

                    break;
                }
            }
        }

        return $targets;
    }

    /**
     * The nullable belongsTo relations this change is about.
     *
     * @return list<Target>
     */
    private function targets(Change $change, Graph $graph): array
    {
        if ($change->node !== null) {
            return $this->nullableRelations($graph, $this->classesNamed($change->node, $graph));
        }

        if ($change->path === null) {
            return [];
        }

        $absolute = $this->project->absolute($change->path);

        if (str_starts_with($absolute, $this->project->migrationsPath)) {
            return $this->targetsForMigration($change, $absolute, $graph);
        }

        foreach ($graph->models as $class => $model) {
            if (is_array($model) && ($model['file'] ?? null) === $change->path) {
                return array_values(array_filter(
                    $this->nullableRelations($graph, [$class]),
                    fn (array $target) => $this->diffMentions($change, $target),
                ));
            }
        }

        return [];
    }

    /**
     * Whether an edit to the model is about this relation: the changed lines
     * sit in its method, or name its method or its column. An edit to the
     * method above, whose trailing context happens to show the relation's
     * declaration, is not about it; saying so every time would be wallpaper.
     *
     * @param  Target  $target
     */
    private function diffMentions(Change $change, array $target): bool
    {
        if (in_array($target['relation'], $change->touchedMethods(), true)) {
            return true;
        }

        foreach ([...$change->addedLines(), ...$change->removedLines()] as $line) {
            if (str_contains($line, $target['relation'].'(') || str_contains($line, $target['column'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The model class a node names, exactly or by unique basename.
     *
     * @return list<string>
     */
    private function classesNamed(string $node, Graph $graph): array
    {
        if (array_key_exists($node, $graph->models)) {
            return [$node];
        }

        $matches = array_values(array_filter(array_keys($graph->models), fn (string $class) => class_basename($class) === $node));

        return count($matches) === 1 ? $matches : [];
    }

    /**
     * A migration edit that adds nullable() to a foreign key column.
     *
     * @return list<Target>
     */
    private function targetsForMigration(Change $change, string $absolute, Graph $graph): array
    {
        $files = new Filesystem;
        $contents = $files->exists($absolute) ? $files->get($absolute) : '';
        $targets = [];

        foreach ($change->addedLines() as $line) {
            if (! str_contains($line, 'nullable(') || preg_match("/'(\\w+)'/", $line, $match) !== 1) {
                continue;
            }

            $column = $match[1];

            foreach (array_keys($graph->models) as $class) {
                foreach ($this->belongsToRelations($graph, $class) as $target) {
                    if ($target['column'] === $column && str_contains($contents, "'{$target['table']}'") && Arr::get($graph->schema, "{$target['table']}.$column.nullable") === true) {
                        $targets[$class.'::'.$target['relation']] = $target;
                    }
                }
            }
        }

        return array_values($targets);
    }

    /**
     * @param  list<string>  $classes
     * @return list<Target>
     */
    private function nullableRelations(Graph $graph, array $classes): array
    {
        $targets = [];

        foreach ($classes as $class) {
            foreach ($this->belongsToRelations($graph, $class) as $target) {
                if ($target['nullable'] === true) {
                    $targets[] = $target;
                }
            }
        }

        return $targets;
    }

    /**
     * Every belongsTo on a model, with the pieces the nudges need to hand.
     *
     * @return list<Target>
     */
    private function belongsToRelations(Graph $graph, string $class): array
    {
        $model = $graph->models[$class] ?? null;
        $relations = is_array($model) && is_array($model['relations'] ?? null) ? $model['relations'] : [];
        $file = is_array($model) && is_string($model['file'] ?? null) ? $model['file'] : '';
        $found = [];

        foreach ($relations as $name => $relation) {
            $foreignKey = is_array($relation) ? ($relation['foreign_key'] ?? null) : null;

            if (! is_array($relation) || ($relation['type'] ?? null) !== 'BelongsTo' || ! is_string($foreignKey) || ! str_contains($foreignKey, '.')) {
                continue;
            }

            $found[] = [
                'class' => $class,
                'model' => class_basename($class),
                'relation' => (string) $name,
                'related' => is_string($relation['related'] ?? null) ? $relation['related'] : '',
                'table' => Str::before($foreignKey, '.'),
                'column' => Str::after($foreignKey, '.'),
                'file' => $file,
                'nullable' => is_bool($relation['nullable'] ?? null) ? $relation['nullable'] : null,
            ];
        }

        return $found;
    }

    /**
     * The migration line(s) that made the column nullable.
     *
     * @param  Target  $target
     * @return list<Nudge>
     */
    private function migrationLines(array $target): array
    {
        $nudges = [];

        foreach ($this->filesUnder($this->project->migrationsPath) as $file) {
            $contents = $file->getContents();

            if (! str_contains($contents, "'{$target['table']}'")) {
                continue;
            }

            foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
                if (str_contains($line, "'{$target['column']}'") && str_contains($line, 'nullable')) {
                    $nudges[] = new Nudge($this->project->relative($file->getPathname()), $index + 1, "{$target['model']}->{$target['relation']} can be null; the migration says so: ".trim($line));
                }
            }
        }

        return $nudges;
    }

    /**
     * Every place that reads through the relation without null-safety: the
     * unguarded reads edges from the templates, plus the same chain in PHP
     * under the app directory where PHPStan saw the receiver still nullable.
     * Variables are matched by name, a heuristic.
     *
     * @param  Target  $target
     * @return list<Nudge>
     */
    private function unguardedReads(array $target, Graph $graph): array
    {
        $relation = preg_quote($target['relation'], '/');
        $sites = [];

        foreach ($graph->edges as $edge) {
            if ($edge['kind'] !== 'reads' || $edge['label'] !== 'unguarded' || $edge['at'] === null) {
                continue;
            }

            if (preg_match('/^\$(\w+)->'.$relation.'->/', $edge['to'], $match) !== 1 || ! $this->variableMatches($match[1], $target, null)) {
                continue;
            }

            $at = $edge['at'];
            $file = Str::beforeLast($at, ':');
            $nudge = new Nudge($file, (int) Str::afterLast($at, ':'), $this->unguardedReason($edge['to'], $target));
            $sites[(string) $nudge] = $nudge;
        }

        $unguarded = $this->unguardedFetches($graph);

        foreach (AppFiles::under($this->project) as ['file' => $file, 'path' => $path]) {
            foreach ($this->regexReads($file->getContents(), $path, $target) as ['nudge' => $nudge, 'property' => $property]) {
                if (in_array("{$target['related']}::$property", $unguarded["$path:{$nudge->line}"] ?? [], true)) {
                    $sites[(string) $nudge] = $nudge;
                }
            }
        }

        return array_values($sites);
    }

    /**
     * Every `$var->relation->x` chain in the text, the variable named for
     * the model, as a nudge plus the property read, one per site. Text
     * only: the caller decides what else has to be true of a site.
     *
     * @param  Target  $target
     * @return list<array{nudge: Nudge, property: string}>
     */
    private function regexReads(string $contents, string $path, array $target): array
    {
        $sites = [];

        foreach (Matches::in('/(\$(\w+)->'.preg_quote($target['relation'], '/').'->(\w+))/', $contents) as [$chain, $line, $variable]) {
            if ($this->variableMatches((string) $variable, $target, $path)) {
                $nudge = new Nudge($path, $line, $this->unguardedReason($chain, $target));
                $sites[(string) $nudge] = ['nudge' => $nudge, 'property' => Str::afterLast($chain, '->')];
            }
        }

        return array_values($sites);
    }

    /**
     * The members (`Class::property`) PHPStan saw fetched through a receiver
     * that could still be null, by site. The symbol index labels a fetch
     * `(unguarded)` only then: a plain `fetches x` means an enclosing guard
     * the regex cannot see, and no edge at all means PHPStan could not type
     * the receiver. The recipe speaks only when the index is sure; a wrong
     * line costs more trust than a missed one (decided 2026-09-12).
     *
     * @return array<string, list<string>>
     */
    private function unguardedFetches(Graph $graph): array
    {
        $unguarded = [];

        foreach ($graph->edges as $edge) {
            if ($edge['kind'] === 'fetches' && $edge['at'] !== null && str_contains($edge['label'], '(unguarded')) {
                $unguarded[$edge['at']][] = $edge['to'];
            }
        }

        return $unguarded;
    }

    /**
     * @param  Target  $target
     */
    private function variableMatches(string $variable, array $target, ?string $path): bool
    {
        if ($variable === 'this') {
            return $path !== null && $path === $target['file'];
        }

        return str_contains(strtolower($variable), strtolower($target['model']));
    }

    /**
     * @param  Target  $target
     */
    private function unguardedReason(string $chain, array $target): string
    {
        return "$chain breaks when {$target['relation']} is null, and it can be (the variable is matched to the model by name, so check it is ".Describe::withArticle($target['model']).')';
    }

    /**
     * Does any factory, seeder or test ever set the column to null?
     *
     * @param  Target  $target
     * @return list<Nudge>
     */
    private function fixtureGap(array $target): array
    {
        $pattern = '/[\'"]'.preg_quote($target['column'], '/').'[\'"]\s*=>\s*null/';

        foreach ([...$this->project->fixturePaths, $this->project->testsPath] as $directory) {
            foreach ($this->filesUnder($directory) as $file) {
                if (preg_match($pattern, $file->getContents()) === 1) {
                    return [];
                }
            }
        }

        return [new Nudge(
            $target['file'],
            null,
            'no factory, seeder or test ever creates '.Describe::withArticle($target['model'])." with a null {$target['relation']}: a green suite proves nothing about that path",
        )];
    }

    /**
     * @return list<SplFileInfo>
     */
    private function filesUnder(string $directory): array
    {
        $files = new Filesystem;

        return $files->isDirectory($directory) ? array_values($files->allFiles($directory)) : [];
    }
}
