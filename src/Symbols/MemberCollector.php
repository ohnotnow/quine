<?php

declare(strict_types=1);

namespace Ohffs\Quine\Symbols;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * One row per resolved consumer of an app-class member: who calls which
 * method, who reads which property. Larastan has already resolved the
 * Eloquent magic by the time a node reaches here.
 *
 * @implements Collector<Expr, array{string, string, string, int}>
 */
final class MemberCollector implements Collector
{
    public function __construct(private readonly string $namespace) {}

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return array{string, string, string, int}|null [to, kind, label, line]
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

            return $this->row($class, $node->name->toString(), 'calls', "calls {$node->name->toString()}()", $node);
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

            return $this->row($this->declaringClassOfProperty(TypeCombinator::removeNull($receiver), $name, $scope), $name, 'fetches', "fetches $name$guard", $node);
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            return $this->row($scope->resolveName($node->class), $node->name->toString(), 'calls', "calls static {$node->name->toString()}()", $node);
        }

        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $class = $scope->resolveName($node->class);

            return $this->row($class, '__construct', 'calls', 'new '.Str::afterLast($class, '\\').'(...)', $node);
        }

        return null;
    }

    private function declaringClassOfMethod(Type $type, string $method, Scope $scope): ?string
    {
        if (! $type->hasMethod($method)->yes()) {
            return null;
        }

        return $type->getMethod($method, $scope)->getDeclaringClass()->getName();
    }

    private function declaringClassOfProperty(Type $type, string $property, Scope $scope): ?string
    {
        if (! $type->hasProperty($property)->yes()) {
            return null;
        }

        return $type->getProperty($property, $scope)->getDeclaringClass()->getName();
    }

    /**
     * @return array{string, string, string, int}|null
     */
    private function row(?string $class, string $member, string $kind, string $label, Node $node): ?array
    {
        if ($class === null || ! str_starts_with($class, $this->namespace)) {
            return null;
        }

        return ["$class::$member", $kind, $label, $node->getStartLine()];
    }
}
