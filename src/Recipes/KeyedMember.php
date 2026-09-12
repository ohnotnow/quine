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

/**
 * A member that feeds a framework sink (a cache key, a storage path, a URL,
 * a config key, a queue name) changed shape. The suite stays green, the key
 * silently becomes "post:42:", production serves a stale or colliding
 * entry. The sink labels on the graph's fetches edges are the map; this
 * recipe reads nothing else.
 *
 * @phpstan-type Keyed array{class: string, member: string, what: string}
 */
final class KeyedMember implements Recipe
{
    public function __construct(private readonly Project $project) {}

    public function nudges(Change $change, Graph $graph): array
    {
        $nudges = [];

        foreach ($this->keyed($change, $graph) as ['class' => $class, 'member' => $member, 'what' => $what]) {
            foreach ($graph->edgesTo("$class::$member") as $edge) {
                $sink = $this->sinkOf($edge['label']);

                if ($sink === null || $edge['at'] === null) {
                    continue;
                }

                $nudges[] = new Nudge(Str::beforeLast($edge['at'], ':'), (int) Str::afterLast($edge['at'], ':'), "the $sink at this line is built from ".class_basename($class)."->$member".($what === '' ? '' : ", which $what"));
            }
        }

        return $nudges;
    }

    /**
     * The members this change is about and what happened to each: every
     * member of a model being asked about; the column a migration edit made
     * nullable or retyped; the accessor or method a model edit removed or
     * changed, under the name it is read by.
     *
     * @return list<Keyed>
     */
    private function keyed(Change $change, Graph $graph): array
    {
        if ($change->node !== null) {
            return $this->membersOf($change->node, $graph);
        }

        if ($change->path === null) {
            return [];
        }

        $absolute = $this->project->absolute($change->path);

        if (str_starts_with($absolute, $this->project->migrationsPath)) {
            return $this->columnsChanged($change, $absolute, $graph);
        }

        foreach ($graph->models as $class => $model) {
            if (is_array($model) && ($model['file'] ?? null) === $change->path) {
                return $this->membersEdited($change, $class);
            }
        }

        return [];
    }

    /**
     * For an ask: the members of the model that feed a sink AND can be null,
     * a column the schema says so of or a nullable belongsTo. A key built
     * from a NOT NULL column is a fact, not a hazard; the nullable recipe
     * draws the same line.
     *
     * @return list<Keyed>
     */
    private function membersOf(string $node, Graph $graph): array
    {
        $classes = array_key_exists($node, $graph->models)
            ? [$node]
            : array_values(array_filter(array_keys($graph->models), fn (string $class) => class_basename($class) === $node));

        if (count($classes) !== 1) {
            return [];
        }

        $class = $classes[0];
        $table = Arr::get($graph->models, "$class.table");
        $found = [];

        foreach ($graph->edges as $edge) {
            if (! str_starts_with($edge['to'], "$class::") || $this->sinkOf($edge['label']) === null) {
                continue;
            }

            $member = Str::after($edge['to'], "$class::");
            $nullable = (is_string($table) && Arr::get($graph->schema, "$table.$member.nullable") === true)
                || Arr::get($graph->models, "$class.relations.$member.nullable") === true;

            if ($nullable) {
                $found[$member] = ['class' => $class, 'member' => $member, 'what' => 'can be null'];
            }
        }

        return array_values($found);
    }

    /**
     * A migration edit whose added line makes a column nullable or gives it
     * a new type, on the models whose table the migration names.
     *
     * @return list<Keyed>
     */
    private function columnsChanged(Change $change, string $absolute, Graph $graph): array
    {
        $files = new Filesystem;
        $contents = $files->exists($absolute) ? $files->get($absolute) : '';
        $removed = $this->columnTypes($change->removedLines());
        $found = [];

        foreach ($this->columnTypes($change->addedLines()) as $column => $type) {
            $what = match (true) {
                $this->addsNullable($change, $column) => 'can now be null: the key changes shape',
                isset($removed[$column]) && $removed[$column] !== $type => 'changed type: the key changes shape',
                default => null,
            };

            if ($what === null) {
                continue;
            }

            foreach ($graph->models as $class => $model) {
                if (is_array($model) && is_string($model['table'] ?? null) && str_contains($contents, "'{$model['table']}'")) {
                    $found[] = ['class' => $class, 'member' => $column, 'what' => $what];
                }
            }
        }

        return $found;
    }

    /**
     * Column name to the schema method that declares it, for every
     * `$table->type('column'` line.
     *
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function columnTypes(array $lines): array
    {
        $types = [];

        foreach ($lines as $line) {
            if (preg_match("/\\\$table->(\\w+)\\('(\\w+)'/", $line, $match) === 1) {
                $types[$match[2]] = $match[1];
            }
        }

        return $types;
    }

    private function addsNullable(Change $change, string $column): bool
    {
        foreach ($change->addedLines() as $line) {
            if (str_contains($line, "'$column'") && str_contains($line, 'nullable(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A model edit that removed or changed a method, under the name the
     * member is read by: an accessor getFooAttribute or foo(): Attribute is
     * read as foo, a scope scopeFoo is called as foo.
     *
     * @return list<Keyed>
     */
    private function membersEdited(Change $change, string $class): array
    {
        $found = [];

        foreach ([['is gone: this line breaks', $change->removedMethods()], ['changed shape: check this line', $change->changedMethods()]] as [$what, $methods]) {
            foreach ($methods as $method) {
                $found[] = ['class' => $class, 'member' => $this->consumedName($method), 'what' => $what];
            }
        }

        foreach ($this->castsChanged($change) as $column) {
            $found[] = ['class' => $class, 'member' => $column, 'what' => 'changed its cast: the key changes shape'];
        }

        return $found;
    }

    /**
     * The columns whose cast the edit changed: a quoted key on a changed line
     * when the hunk sits inside the model's casts (the `casts` word is within
     * the context git prints around the change).
     *
     * @return list<string>
     */
    private function castsChanged(Change $change): array
    {
        $inCasts = false;

        foreach ($change->nearbyLines() as $line) {
            if (preg_match('/\bcasts\b/', $line) === 1) {
                $inCasts = true;

                break;
            }
        }

        if (! $inCasts) {
            return [];
        }

        $columns = [];

        foreach ([...$change->removedLines(), ...$change->addedLines()] as $line) {
            if (preg_match("/^\s*'(\w+)'\s*=>/", $line, $match) === 1) {
                $columns[$match[1]] = true;
            }
        }

        return array_keys($columns);
    }

    private function consumedName(string $method): string
    {
        return match (true) {
            preg_match('/^scope([A-Z]\w*)$/', $method, $match) === 1 => lcfirst($match[1]),
            preg_match('/^[gs]et([A-Z]\w*)Attribute$/', $method, $match) === 1 => Str::snake($match[1]),
            default => Str::snake($method),
        };
    }

    /**
     * The sink a labelled edge feeds, from the last marker in its bracket, or null.
     */
    private function sinkOf(string $label): ?string
    {
        if (preg_match('/\((?:[^()]*, )?(cache key|redis key|storage path|url|config key|queue)\)$/', $label, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
