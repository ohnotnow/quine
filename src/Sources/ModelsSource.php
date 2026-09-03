<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Sources;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Project;
use Ohwhatnow\Quine\Support\AppFiles;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Every Eloquent model under the app's Models directory: table, columns joined
 * from the schema, and relations with their foreign key and nullability.
 */
final class ModelsSource implements Source
{
    public function collect(Project $project, Graph $graph): void
    {
        foreach (AppFiles::under($project, 'Models') as ['class' => $class, 'path' => $path]) {
            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! (new ReflectionClass($class))->isInstantiable()) {
                continue;
            }

            $model = new $class;

            $graph->models[$class] = [
                'file' => $path,
                'table' => $model->getTable(),
                'columns' => $graph->schema[$model->getTable()] ?? [],
                'relations' => $this->relations($model, $path, $graph),
                'accessors' => $this->accessors($model),
                'casts' => $model->getCasts(),
                'scopes' => $this->scopes($model),
            ];
        }
    }

    /**
     * Public zero-parameter methods declared on the model whose return type is a Relation.
     *
     * @return array<string, array<string, mixed>>
     */
    private function relations(Model $model, string $path, Graph $graph): array
    {
        $class = $model::class;
        $relations = [];

        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || $method->isStatic() || $method->getNumberOfParameters() > 0) {
                continue;
            }

            $type = $method->getReturnType();

            if (! $type instanceof ReflectionNamedType || ! is_subclass_of($type->getName(), Relation::class)) {
                continue;
            }

            $relation = $model->{$method->name}();

            if (! $relation instanceof Relation) {
                continue;
            }

            $relations[$method->name] = $this->describe($relation, $graph);

            $graph->edge($class, $relation->getRelated()::class, 'relation', $method->name.'() '.class_basename($relation), $path.':'.$method->getStartLine());
        }

        return $relations;
    }

    /**
     * Methods declared on the model that return an Attribute, as snake_case attribute names.
     *
     * @return list<string>
     */
    private function accessors(Model $model): array
    {
        return array_values($this->declaredMethods($model)
            ->filter(fn (ReflectionMethod $method) => $method->getReturnType() instanceof ReflectionNamedType && $method->getReturnType()->getName() === Attribute::class)
            ->map(fn (ReflectionMethod $method) => Str::snake($method->name))
            ->all());
    }

    /**
     * Local scopes declared on the model, named as they are called.
     *
     * @return list<string>
     */
    private function scopes(Model $model): array
    {
        return array_values($this->declaredMethods($model)
            ->filter(fn (ReflectionMethod $method) => str_starts_with($method->name, 'scope'))
            ->map(fn (ReflectionMethod $method) => lcfirst(substr($method->name, 5)))
            ->all());
    }

    /**
     * @return Collection<int, ReflectionMethod>
     */
    private function declaredMethods(Model $model): Collection
    {
        return (new Collection((new ReflectionClass($model))->getMethods()))
            ->filter(fn (ReflectionMethod $method) => $method->class === $model::class);
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     * @return array<string, mixed>
     */
    private function describe(Relation $relation, Graph $graph): array
    {
        $described = ['type' => class_basename($relation), 'related' => $relation->getRelated()::class];

        if ($relation instanceof BelongsTo) {
            $table = $relation->getParent()->getTable();
            $column = $relation->getForeignKeyName();
            $nullable = Arr::get($graph->schema, "$table.$column.nullable");

            $described['foreign_key'] = "$table.$column";
            $described['nullable'] = is_bool($nullable) ? $nullable : null;
        }

        if ($relation instanceof HasOneOrMany) {
            $described['foreign_key'] = $relation->getRelated()->getTable().'.'.$relation->getForeignKeyName();
        }

        if ($relation instanceof BelongsToMany) {
            $described['pivot'] = $relation->getTable();
            $described['pivot_keys'] = [$relation->getForeignPivotKeyName(), $relation->getRelatedPivotKeyName()];
        }

        return $described;
    }
}
