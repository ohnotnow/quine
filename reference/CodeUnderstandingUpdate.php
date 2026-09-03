<?php

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Larastan\Larastan\Properties\MigrationHelper;
use Livewire\Blaze\BladeService;
use PHPStan\DependencyInjection\ContainerFactory;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * SPIKE - deliberately ugly feasibility probe, not production code.
 *
 * Builds a graph of how the app hangs together beneath the plain PHP
 * (schema nullability, relations, model event hooks, listeners, routes,
 * Livewire views, Blade edges, gates, schedule, test coverage) and prints
 * the "hang on a minute" nudges a developer who knew the codebase would give.
 */
class CodeUnderstandingUpdate extends Command
{
    protected $signature = 'code-understanding:update
                            {--json=storage/app/code-understanding.json : Where to write the graph}
                            {--focus= : Print everything within two hops of this node, e.g. App\\Models\\Note}';

    protected $description = 'Spike: build a graph of the hidden edges in this app and print nudges';

    /** @var array<int, array{from: string, to: string, kind: string, label: string, at: ?string}> */
    private array $edges = [];

    public function handle(): int
    {
        $schema = $this->schema();
        $models = $this->models($schema);
        $this->modelEvents(array_keys($models));
        $this->listeners();
        $this->routes();
        $this->livewireViews();
        $this->uses();
        $this->views();
        $this->gates();
        $this->schedule();
        $coverage = $this->coverage();

        $graph = ['schema' => $schema, 'models' => $models, 'edges' => $this->edges, 'coverage' => $coverage];
        File::ensureDirectoryExists(dirname(base_path($this->option('json'))));
        File::put(base_path($this->option('json')), json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->reportModels($models);
        $this->reportHiddenEdges();
        $this->reportPages($coverage);
        $this->reportNudges($models);

        if ($this->option('focus')) {
            $this->reportNeighbourhood($this->option('focus'));
        }

        $this->newLine();
        $this->info(count($this->edges).' edges written to '.$this->option('json'));

        return self::SUCCESS;
    }

    /**
     * Column nullability straight from the migrations, via larastan's own
     * migration reader (so no database connection is needed).
     *
     * @return array<string, array<string, array{type: string, nullable: bool}>>
     */
    private function schema(): array
    {
        defined('LARAVEL_VERSION') || define('LARAVEL_VERSION', app()->version());

        $container = (new ContainerFactory(base_path()))->create(
            storage_path('framework/cache/code-understanding'),
            [base_path('phpstan.neon')],
            [base_path('app')],
        );

        $schema = [];
        foreach ($container->getByType(MigrationHelper::class)->initializeTables() as $table) {
            foreach ($table->columns as $column) {
                $schema[$table->name][$column->name] = ['type' => $column->readableType, 'nullable' => $column->nullable];
            }
        }

        return $schema;
    }

    /** @return array<string, array<string, mixed>> */
    private function models(array $schema): array
    {
        $models = [];
        foreach (File::allFiles(app_path('Models')) as $file) {
            $class = 'App\\Models\\'.str_replace('/', '\\', Str::before($file->getRelativePathname(), '.php'));
            $reflection = new ReflectionClass($class);
            if (! $reflection->isSubclassOf(Model::class) || ! $reflection->isInstantiable()) {
                continue;
            }
            $model = new $class;
            $path = $this->relativePath($file->getPathname());

            $relations = [];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $class || $method->isStatic() || $method->getNumberOfParameters() > 0) {
                    continue;
                }
                $type = $method->getReturnType();
                if (! $type instanceof ReflectionNamedType || ! is_subclass_of($type->getName(), Relation::class)) {
                    continue;
                }
                $relation = $model->{$method->name}();
                $relations[$method->name] = $this->describeRelation($relation, $schema);
                $this->edge($class, $relation->getRelated()::class, 'relation', $method->name.'() '.class_basename($relation), "$path:{$method->getStartLine()}");
            }

            $accessors = collect($reflection->getMethods())
                ->filter(fn (ReflectionMethod $m) => $m->class === $class && $m->getReturnType() instanceof ReflectionNamedType && $m->getReturnType()->getName() === Attribute::class)
                ->map(fn (ReflectionMethod $m) => Str::snake($m->name))
                ->values()->all();

            $scopes = collect($reflection->getMethods())
                ->filter(fn (ReflectionMethod $m) => $m->class === $class && str_starts_with($m->name, 'scope'))
                ->map(fn (ReflectionMethod $m) => lcfirst(substr($m->name, 5)))
                ->values()->all();

            $models[$class] = [
                'file' => $path,
                'table' => $model->getTable(),
                'columns' => $schema[$model->getTable()] ?? [],
                'relations' => $relations,
                'accessors' => $accessors,
                'casts' => $model->getCasts(),
                'scopes' => $scopes,
            ];
        }

        return $models;
    }

    /** @return array<string, mixed> */
    private function describeRelation(Relation $relation, array $schema): array
    {
        $described = ['type' => class_basename($relation), 'related' => $relation->getRelated()::class];

        if ($relation instanceof BelongsTo) {
            $table = $relation->getParent()->getTable();
            $column = $relation->getForeignKeyName();
            $described['foreign_key'] = "$table.$column";
            $described['nullable'] = $schema[$table][$column]['nullable'] ?? null;
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

    /**
     * The closures registered in booted()/observers - invisible from the
     * outside of the model file, but the dispatcher knows them all.
     *
     * @param  array<int, string>  $classes
     */
    private function modelEvents(array $classes): void
    {
        foreach (Event::getRawListeners() as $event => $listeners) {
            if (! str_starts_with($event, 'eloquent.')) {
                continue;
            }
            [$hook, $class] = explode(': ', $event, 2);
            if (! in_array($class, $classes)) {
                continue;
            }
            foreach ($listeners as $listener) {
                $this->edge($class, $this->describeCallable($listener), 'model-event', Str::after($hook, 'eloquent.'), null);
            }
        }
    }

    private function listeners(): void
    {
        foreach (Event::getRawListeners() as $event => $listeners) {
            if (str_starts_with($event, 'eloquent.')) {
                continue;
            }
            foreach ($listeners as $listener) {
                $target = $this->describeCallable($listener);
                if (! str_starts_with($event, 'App\\') && ! str_contains($target, 'App\\')) {
                    continue;
                }
                $this->edge($event, $target, 'event', $this->isQueued($listener) ? 'queued listener' : 'listener', null);
            }
        }
    }

    private function routes(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $handler = $route->getActionName();
            if (! str_starts_with($handler, 'App\\')) {
                continue;
            }
            $name = implode('|', $route->methods()).' /'.$route->uri().($route->getName() ? " [{$route->getName()}]" : '');
            $label = str_contains($handler, '@') ? Str::after($handler, '@') : 'livewire page';
            $this->edge("route $name", Str::before($handler, '@'), 'route', $label.' '.implode(',', $route->gatherMiddleware()), null);
        }
    }

    private function livewireViews(): void
    {
        foreach (File::allFiles(app_path('Livewire')) as $file) {
            $class = 'App\\Livewire\\'.str_replace('/', '\\', Str::before($file->getRelativePathname(), '.php'));
            foreach ($this->matches('/view\(\s*[\'"]([\w.\-]+)[\'"]/', $file->getContents()) as [$view, $line]) {
                $this->edge($class, $this->viewPath($view), 'renders', $view, $this->relativePath($file->getPathname()).":$line");
            }
        }
    }

    /**
     * The plain static layer: which app class mentions which other app class.
     */
    private function uses(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            $from = 'App\\'.str_replace('/', '\\', Str::before($file->getRelativePathname(), '.php'));
            $path = $this->relativePath($file->getPathname());
            $seen = [];
            foreach ($this->matches('/(?:^use |[^\w\\\\])(App\\\\[\w\\\\]+)(?:;|::|\s|\()/m', $file->getContents()) as [$to, $line]) {
                if ($to === $from || isset($seen[$to]) || ! (class_exists($to) || interface_exists($to) || enum_exists($to))) {
                    continue;
                }
                $seen[$to] = true;
                $this->edge($from, $to, 'uses', 'uses', "$path:$line");
            }
        }
    }

    private function views(): void
    {
        $blade = app(BladeService::class);

        foreach (File::allFiles(resource_path('views')) as $file) {
            $path = $this->relativePath($file->getPathname());
            if (str_starts_with($path, 'resources/views/vendor/')) {
                continue;
            }
            $source = $file->getContents();

            foreach ($this->matches('/<x-([\w.:-]+)/', $source) as [$name, $line]) {
                $target = $blade->componentNameToPath($name);
                $this->edge($path, $target === '' ? "component:$name" : $this->relativePath($target), 'component', "<x-$name>", "$path:$line");
            }
            foreach ($this->matches('/<flux:([\w.:-]+)/', $source) as [$name, $line]) {
                $this->edge($path, "flux:$name", 'flux', "<flux:$name>", "$path:$line");
            }
            foreach ($this->matches('/<livewire:([\w.\-]+)/', $source) as [$name, $line]) {
                $this->edge($path, $this->livewireClass($name), 'livewire', "<livewire:$name>", "$path:$line");
            }
            foreach ($this->matches('/@livewire\(\s*[\'"]([\w.\-]+)/', $source) as [$name, $line]) {
                $this->edge($path, $this->livewireClass($name), 'livewire', "@livewire('$name')", "$path:$line");
            }
            foreach ($this->matches('/@(?:include|includeIf|includeWhen|includeUnless|includeFirst|extends|each)\(\s*[\'"]([\w.\-]+)/', $source) as [$name, $line]) {
                $this->edge($path, $this->viewPath($name), 'include', $name, "$path:$line");
            }
            foreach ($this->matches('/@(?:can|cannot|canany|elsecan)\(\s*[\'"](\w+)/', $source) as [$name, $line]) {
                $this->edge($path, "gate:$name", 'gate', "@can('$name')", "$path:$line");
            }
            foreach ($this->matches('/(\$\w+(?:\??->\w+)+)/', $source) as [$chain, $line]) {
                $this->edge($path, $chain, 'reads', str_contains($chain, '?->') ? 'null-safe' : 'unguarded', "$path:$line");
            }
        }
    }

    private function gates(): void
    {
        foreach (Gate::abilities() as $name => $callback) {
            $this->edge("gate:$name", $this->describeCallable($callback), 'gate', 'defined by', null);
        }
        foreach (Gate::policies() as $model => $policy) {
            $this->edge($model, $policy, 'policy', 'policy', null);
        }
    }

    private function schedule(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            $this->edge('schedule', $event->getSummaryForDisplay(), 'schedule', $event->getExpression(), null);
        }
    }

    /**
     * Which test files execute which source files, from pest's tia cache.
     *
     * @return array<string, array<int, string>>
     */
    private function coverage(): array
    {
        $graphs = glob(($_SERVER['HOME'] ?? '').'/.pest/tia/'.basename(base_path()).'-*/graph.json') ?: [];
        if ($graphs === []) {
            return [];
        }
        $graph = json_decode(File::get($graphs[0]), true);

        $byFile = [];
        foreach ($graph['edges'] as $test => $indices) {
            foreach ($indices as $index) {
                $byFile[$graph['files'][$index]][] = $test;
            }
        }

        return $byFile;
    }

    // ----- reports -----------------------------------------------------

    private function reportModels(array $models): void
    {
        $this->components->twoColumnDetail('<fg=yellow>MODELS</>', 'relations, hooks, accessors');
        foreach ($models as $class => $model) {
            $this->line("  <fg=cyan>$class</> ({$model['table']})");
            foreach ($model['relations'] as $name => $relation) {
                $extra = match (true) {
                    isset($relation['nullable']) => $relation['nullable'] ? '<fg=red>NULLABLE</> '.$relation['foreign_key'] : 'not null '.$relation['foreign_key'],
                    isset($relation['pivot']) => 'via '.$relation['pivot'],
                    isset($relation['foreign_key']) => $relation['foreign_key'],
                    default => '',
                };
                $this->line("    {$name}(): {$relation['type']} -> ".class_basename($relation['related'])."  $extra");
            }
            foreach ($this->edgesFrom($class, 'model-event') as $edge) {
                $this->line("    on <fg=magenta>{$edge['label']}</> -> {$edge['to']}");
            }
            if ($model['accessors'] !== []) {
                $this->line('    accessors: '.implode(', ', $model['accessors']));
            }
            if ($model['casts'] !== []) {
                $this->line('    casts: '.collect($model['casts'])->map(fn ($cast, $col) => "$col=".class_basename($cast))->implode(', '));
            }
        }
    }

    private function reportHiddenEdges(): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>HIDDEN EDGES</>', 'events, gates, schedule');
        foreach ($this->edges as $edge) {
            if (in_array($edge['kind'], ['event', 'gate', 'policy', 'schedule']) && ! str_starts_with($edge['from'], 'resources/')) {
                $this->line("  {$edge['from']} --{$edge['label']}--> {$edge['to']}");
            }
        }
    }

    private function reportPages(array $coverage): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>PAGES</>', 'route -> handler -> view (tests rendering it)');
        $this->warnIfCoverageIsStale($coverage);
        foreach ($this->edges as $route) {
            if ($route['kind'] !== 'route') {
                continue;
            }
            $this->line("  <fg=cyan>{$route['from']}</> -> ".class_basename($route['to']));
            foreach ($this->edgesFrom($route['to'], 'renders') as $render) {
                $tests = $coverage[$render['to']] ?? [];
                $flag = $tests === [] ? '<fg=red>no test renders this</>' : count($tests).' test files';
                $this->line("      {$render['to']}  ($flag)");
                foreach ($this->edgesFrom($render['to'], 'livewire') as $child) {
                    $this->line('        <livewire> '.class_basename($child['to']));
                }
                foreach ($this->edgesFrom($render['to'], 'gate') as $gate) {
                    $this->line("        {$gate['label']}");
                }
            }
        }
    }

    private function warnIfCoverageIsStale(array $coverage): void
    {
        if ($coverage === []) {
            $this->line('  <fg=yellow>no pest tia cache found - run vendor/bin/pest --tia to get test coverage</>');

            return;
        }
        $known = collect($coverage)->flatten()->unique();
        $missing = collect(File::allFiles(base_path('tests')))
            ->map(fn ($file) => $this->relativePath($file->getPathname()))
            ->filter(fn ($path) => str_ends_with($path, 'Test.php') && ! $known->contains($path));
        if ($missing->isNotEmpty()) {
            $this->line('  <fg=yellow>tia cache is stale - '.$missing->count().' test files are not in it ('.$missing->map(fn ($p) => basename($p))->implode(', ').') - re-run vendor/bin/pest --tia</>');
        }
    }

    /**
     * Recipe one: a belongsTo whose foreign key is nullable. Who still
     * assumes it is always set, and does any fixture ever make it null?
     */
    private function reportNudges(array $models): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>NUDGES</>', 'nullable foreign keys');
        foreach ($models as $class => $model) {
            foreach ($model['relations'] as $name => $relation) {
                if (($relation['nullable'] ?? false) !== true) {
                    continue;
                }
                $this->line('  <fg=red>'.class_basename($class)."->$name</> can be null ({$relation['foreign_key']} is nullable)");
                foreach ($this->migrationLines(Str::before($relation['foreign_key'], '.'), Str::after($relation['foreign_key'], '.')) as $line) {
                    $this->line("    migration: $line");
                }

                $unsafe = $this->unguardedDereferences($class, $name);
                $this->line($unsafe === [] ? "    every ->$name-> dereference found is null-safe" : '    <fg=red>unguarded</> dereferences:');
                foreach ($unsafe as $site) {
                    $this->line("      $site");
                }

                $fixtures = $this->fixturesProducingNull(Str::after($relation['foreign_key'], '.'));
                $this->line($fixtures === []
                    ? '    <fg=red>no factory, seeder or test ever creates a '.class_basename($class)." with a null $name</> - a green suite proves nothing about that path"
                    : '    fixtures producing null: '.implode(', ', $fixtures));
            }
        }
    }

    private function reportNeighbourhood(string $node): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>NEIGHBOURHOOD</>', $node);
        $seen = [$node => 0];
        $queue = [$node];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($seen[$current] >= 2) {
                continue;
            }
            foreach ($this->edges as $edge) {
                if ($edge['kind'] === 'reads' || $edge['kind'] === 'flux') {
                    continue;
                }
                foreach ([[$edge['from'], $edge['to'], '->'], [$edge['to'], $edge['from'], '<-']] as [$here, $there, $arrow]) {
                    if ($here !== $current || isset($seen[$there])) {
                        continue;
                    }
                    $seen[$there] = $seen[$current] + 1;
                    $queue[] = $there;
                    $this->line(str_repeat('    ', $seen[$there])."$arrow [{$edge['kind']}: {$edge['label']}] $there".($edge['at'] ? "  <fg=gray>{$edge['at']}</>" : ''));
                }
            }
        }
    }

    // ----- helpers -----------------------------------------------------

    /**
     * Heuristic: a `$something->relation->x` chain where the variable name
     * contains the model name (`$activity`, `$previewNote`), or `$this` in
     * the model's own file.
     *
     * @return array<int, string>
     */
    private function unguardedDereferences(string $class, string $relation): array
    {
        $modelName = strtolower(class_basename($class));
        $hits = [];
        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                $path = $this->relativePath($file->getPathname());
                foreach ($this->matches('/(\$(\w+)->'.preg_quote($relation, '/').'->\w+)/', $file->getContents()) as [$chain, $line, $variable]) {
                    $isThisModel = str_contains(strtolower($variable), $modelName) || ($variable === 'this' && str_ends_with($path, class_basename($class).'.php'));
                    if ($isThisModel) {
                        $hits[] = "$path:$line  $chain";
                    }
                }
            }
        }

        return $hits;
    }

    /** @return array<int, string> */
    private function fixturesProducingNull(string $column): array
    {
        $hits = [];
        foreach ([database_path('factories'), database_path('seeders'), base_path('tests')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                foreach ($this->matches('/([\'"]'.preg_quote($column, '/').'[\'"]\s*=>\s*null)/', $file->getContents()) as [, $line]) {
                    $hits[] = $this->relativePath($file->getPathname()).":$line";
                }
            }
        }

        return $hits;
    }

    /** @return array<int, string> */
    private function migrationLines(string $table, string $column): array
    {
        $hits = [];
        foreach (File::allFiles(database_path('migrations')) as $file) {
            if (! str_contains($file->getContents(), "'$table'")) {
                continue;
            }
            foreach ($this->matches('/(.*[\'"]'.preg_quote($column, '/').'[\'"].*nullable.*)/', $file->getContents()) as [$line, $number]) {
                $hits[] = $file->getFilename().":$number  ".trim($line);
            }
        }

        return $hits;
    }

    /**
     * Every match of the pattern with the line it sits on.
     *
     * @return array<int, array{0: string, 1: int, 2?: string}>
     */
    private function matches(string $pattern, string $source): array
    {
        preg_match_all($pattern, $source, $sets, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(function (array $set) use ($source) {
            $line = substr_count($source, "\n", 0, $set[1][1]) + 1;

            return [$set[1][0], $line, $set[2][0] ?? null];
        }, $sets);
    }

    /** @return array<int, array{from: string, to: string, kind: string, label: string, at: ?string}> */
    private function edgesFrom(string $node, string $kind): array
    {
        return array_values(array_filter($this->edges, fn ($edge) => $edge['from'] === $node && $edge['kind'] === $kind));
    }

    private function edge(string $from, string $to, string $kind, string $label, ?string $at): void
    {
        $this->edges[] = compact('from', 'to', 'kind', 'label', 'at');
    }

    private function describeCallable(mixed $callable): string
    {
        if ($callable instanceof Closure) {
            $reflection = new ReflectionFunction($callable);

            return 'closure '.$this->relativePath((string) $reflection->getFileName()).':'.$reflection->getStartLine();
        }
        if (is_array($callable)) {
            return implode('@', array_map(fn ($part) => is_object($part) ? $part::class : $part, $callable));
        }
        if (is_object($callable)) {
            return $callable::class;
        }

        return (string) $callable;
    }

    private function isQueued(mixed $listener): bool
    {
        if (! is_string($listener)) {
            return false;
        }

        return is_subclass_of(Str::before($listener, '@'), ShouldQueue::class);
    }

    private function livewireClass(string $name): string
    {
        return 'App\\Livewire\\'.collect(explode('.', $name))->map(fn (string $part) => Str::studly($part))->implode('\\');
    }

    private function viewPath(string $name): string
    {
        try {
            return $this->relativePath(view()->getFinder()->find($name));
        } catch (InvalidArgumentException) {
            return "view:$name (missing)";
        }
    }

    private function relativePath(string $path): string
    {
        return Str::after($path, base_path().'/');
    }
}
