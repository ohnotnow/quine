<?php

declare(strict_types=1);

namespace Ohffs\Quine\Symbols;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
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
 * @implements Collector<Expr, array{string, string, string, int, ?string}>
 */
final class MemberCollector implements Collector
{
    /**
     * The static methods Illuminate's Dispatchable traits give an app event or
     * job: declared in vendor, but the coupling they express is the app's.
     */
    private const array DISPATCHES = ['dispatch', 'dispatchIf', 'dispatchUnless', 'dispatchSync', 'dispatchNow', 'dispatchAfterResponse', 'broadcast'];

    public function __construct(
        private readonly string $namespace,
        private readonly string $appPath,
    ) {}

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return array{string, string, string, int, ?string}|null [to, kind, label, line, enclosing method]
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        // PHPStan also walks a nullsafe fetch or call as a plain one with the
        // receiver narrowed to non-null; the nullsafe node itself is the record.
        if ($node->getAttribute('virtualNullsafePropertyFetch') === true || $node->getAttribute('virtualNullsafeMethodCall') === true) {
            return null;
        }

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

            // Dispatching an app event or job is the app's own doing, however the trait spells it.
            if (in_array($method, self::DISPATCHES, true) && str_starts_with($class->getName(), $this->namespace)) {
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
     * @return array{string, string, string, int, ?string}|null
     */
    private function row(?string $class, string $member, string $kind, string $label, Node $node, Scope $scope): ?array
    {
        if ($class === null || ! str_starts_with($class, $this->namespace)) {
            return null;
        }

        return ["$class::$member", $kind, $label, $node->getStartLine(), $this->enclosingMethod($scope)];
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
