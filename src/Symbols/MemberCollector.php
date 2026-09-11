<?php

declare(strict_types=1);

namespace Ohffs\Quine\Symbols;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * One row per resolved consumer of an app-class member: who calls which
 * method, who reads which property. Larastan has already resolved the
 * Eloquent magic by the time a node reaches here.
 *
 * @implements Collector<Expr, array{string, string, string, int, ?string, int}>
 */
final class MemberCollector implements Collector
{
    /**
     * Framework statics that are the way in to an app class: the Dispatchable
     * traits' dispatch family on an event or job, JsonResource's collection
     * and make on a resource. Declared in vendor, but the coupling they
     * express is the app's.
     */
    private const array ENTRIES = ['dispatch', 'dispatchIf', 'dispatchUnless', 'dispatchSync', 'dispatchNow', 'dispatchAfterResponse', 'broadcast', 'collection', 'make'];

    /**
     * Framework calls whose first argument is a key, a path, a URL or a name
     * built by the app, by facade or helper name (a heuristic: the facade
     * resolves to a framework class). Method name null means any method.
     *
     * @var array<string, array{methods: ?list<string>, label: string}>
     */
    private const array SINKS = [
        'Cache' => ['methods' => ['get', 'put', 'remember', 'rememberForever', 'forget', 'has', 'add', 'increment', 'decrement', 'tags', 'pull', 'forever', 'missing'], 'label' => 'cache key'],
        'cache' => ['methods' => null, 'label' => 'cache key'],
        'Redis' => ['methods' => null, 'label' => 'redis key'],
        'Storage' => ['methods' => ['put', 'get', 'exists', 'missing', 'delete', 'url', 'path', 'download', 'append', 'prepend', 'copy', 'move', 'size', 'lastModified', 'temporaryUrl', 'readStream', 'writeStream'], 'label' => 'storage path'],
        'Http' => ['methods' => ['get', 'post', 'put', 'patch', 'delete', 'head'], 'label' => 'url'],
        'config' => ['methods' => null, 'label' => 'config key'],
        'Config' => ['methods' => ['get', 'has', 'string', 'integer', 'boolean', 'array'], 'label' => 'config key'],
        'onQueue' => ['methods' => null, 'label' => 'queue'],
        'onConnection' => ['methods' => null, 'label' => 'queue'],
    ];

    /**
     * File, then start position, of every fetch or call sitting inside a sink's
     * key argument, with the sink's label. Filled when the sink call is met,
     * read when the nodes inside it are.
     *
     * @var array<string, array<int, string>>
     */
    private array $sinks = [];

    public function __construct(
        private readonly string $namespace,
        private readonly string $appPath,
    ) {}

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return array{string, string, string, int, ?string, int}|null [to, kind, label, line, enclosing method, file position]
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        // PHPStan also walks a nullsafe fetch or call as a plain one with the
        // receiver narrowed to non-null; the nullsafe node itself is the record.
        if ($node->getAttribute('virtualNullsafePropertyFetch') === true || $node->getAttribute('virtualNullsafeMethodCall') === true) {
            return null;
        }

        $this->markSink($node, $scope);

        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && $node->name instanceof Identifier) {
            $class = $this->declaringClassOfMethod(TypeCombinator::removeNull($scope->getType($node->var)), $node->name->toString(), $scope);

            return $this->row($class, $node->name->toString(), 'calls', "calls {$node->name->toString()}()", $node, $scope);
        }

        if (($node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) && $node->name instanceof Identifier) {
            $receiver = $scope->getType($node->var);
            $name = $node->name->toString();
            // Inside a ?-> the receiver is already narrowed, so the operator is the signal.
            $guard = match (true) {
                $node instanceof Expr\NullsafePropertyFetch => ' (null-safe)',
                TypeCombinator::containsNull($receiver) => ' (unguarded)',
                default => '',
            };

            return $this->row($this->declaringClassOfProperty(TypeCombinator::removeNull($receiver), $name, $scope), $name, 'fetches', "fetches $name$guard", $node, $scope);
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $class = $this->declaringClassOfMethod($scope->resolveTypeByName($node->class), $node->name->toString(), $scope);

            return $this->row($class, $node->name->toString(), 'calls', "calls static {$node->name->toString()}()", $node, $scope);
        }

        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $class = $scope->resolveName($node->class);

            return $this->row($class, '__construct', 'calls', 'new '.Str::afterLast($class, '\\').'(...)', $node, $scope);
        }

        return null;
    }

    /**
     * The class whose file declares the method, or null when the app does not
     * declare it. Larastan reports Eloquent's magic (factory, where, withTrashed)
     * as declared on the model and a scope as declared on the builder, so the
     * candidates are the declaring class, the receiver's class and, on a
     * builder, its model; the test is the native method's file, scope form
     * included.
     */
    private function declaringClassOfMethod(Type $type, string $method, Scope $scope): ?string
    {
        if (! $type->hasMethod($method)->yes()) {
            return null;
        }

        $candidates = [
            $type->getMethod($method, $scope)->getDeclaringClass(),
            ...$type->getObjectClassReflections(),
            ...$type->getTemplateType(EloquentBuilder::class, 'TModel')->getObjectClassReflections(),
        ];

        foreach ($candidates as $class) {
            foreach ([$method, 'scope'.ucfirst($method)] as $name) {
                if ($this->declaredInApp($class, $name)) {
                    return $class->getName();
                }
            }

            // Dispatching an app event or job, or collecting an app resource, is the app's own doing.
            if (in_array($method, self::ENTRIES, true) && str_starts_with($class->getName(), $this->namespace)) {
                return $class->getName();
            }
        }

        return null;
    }

    private function declaredInApp(ClassReflection $class, string $method): bool
    {
        if (! $class->hasNativeMethod($method)) {
            return false;
        }

        $file = $class->getNativeReflection()->getMethod($method)->getFileName();

        return is_string($file) && str_starts_with($file, rtrim($this->appPath, '/').'/');
    }

    private function declaringClassOfProperty(Type $type, string $property, Scope $scope): ?string
    {
        if (! $type->hasProperty($property)->yes()) {
            return null;
        }

        return $type->getProperty($property, $scope)->getDeclaringClass()->getName();
    }

    /**
     * @return array{string, string, string, int, ?string, int}|null
     */
    private function row(?string $class, string $member, string $kind, string $label, Node $node, Scope $scope): ?array
    {
        if ($class === null || ! str_starts_with($class, $this->namespace)) {
            return null;
        }

        return ["$class::$member", $kind, $label, $node->getStartLine(), $this->enclosingMethod($scope), $node->getStartFilePos()];
    }

    /**
     * The sink whose key argument holds the node at this position in the
     * file, or null. Asked after the file has been collected: PHPStan hands
     * the expressions inside an argument to the collector BEFORE the call
     * around them, so a row cannot carry its sink when it is made.
     */
    public function sinkAt(string $file, int $position): ?string
    {
        return $this->sinks[$file][$position] ?? null;
    }

    /**
     * When the node is a sink call, remember every fetch and call inside its
     * key argument so their rows can be told what they feed.
     */
    private function markSink(Node $node, Scope $scope): void
    {
        $label = $this->sinkLabel($node);

        if ($label === null || ! $node instanceof Expr\CallLike || $node->isFirstClassCallable() || $node->getArgs() === []) {
            return;
        }

        foreach ((new NodeFinder)->findInstanceOf($node->getArgs()[0]->value, Expr::class) as $inner) {
            $this->sinks[$scope->getFile()][$inner->getStartFilePos()] = $label;
        }
    }

    /**
     * The sink label for a Facade::method(), helper(), Storage::disk()->method()
     * or ->onQueue() call, or null.
     */
    private function sinkLabel(Node $node): ?string
    {
        [$facade, $method] = match (true) {
            $node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Identifier => [$node->class->getLast(), $node->name->toString()],
            $node instanceof Expr\FuncCall && $node->name instanceof Name => [$node->name->getLast(), null],
            $node instanceof Expr\MethodCall && $node->name instanceof Identifier => [$this->facadeBehind($node->var) ?? $node->name->toString(), $node->name->toString()],
            default => [null, null],
        };

        $sink = self::SINKS[$facade] ?? null;

        if ($sink === null) {
            return null;
        }

        return $sink['methods'] === null || in_array($method, $sink['methods'], true) ? $sink['label'] : null;
    }

    /**
     * The facade a chain such as Storage::disk('x')->put() hangs off, or null.
     */
    private function facadeBehind(Expr $receiver): ?string
    {
        while ($receiver instanceof Expr\MethodCall) {
            $receiver = $receiver->var;
        }

        return $receiver instanceof Expr\StaticCall && $receiver->class instanceof Name ? $receiver->class->getLast() : null;
    }

    /**
     * The named method the node sits in, through any closures; null at class
     * or file level.
     */
    private function enclosingMethod(Scope $scope): ?string
    {
        for ($current = $scope; $current !== null; $current = $current->getParentScope()) {
            $function = $current->getFunction();

            if ($function instanceof MethodReflection) {
                return $function->getName();
            }
        }

        return null;
    }
}
