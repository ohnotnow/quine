<?php

declare(strict_types=1);

namespace Ohffs\Quine\Support;

use Bladestan\Compiler\BladeToPHPCompiler;
use Bladestan\NodeAnalyzer\BladeViewMethodsMatcher;
use Bladestan\NodeAnalyzer\LaravelViewFunctionMatcher;
use Bladestan\NodeAnalyzer\MailablesContentMatcher;
use Bladestan\NodeAnalyzer\TemplateFilePathResolver;
use InvalidArgumentException;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\Container;
use PHPStan\Type\Type;
use ReflectionClass;

/**
 * The one class that names Bladestan. It is optional for the host app (a
 * composer suggest), and its maintainer is steering users to a rewrite, so
 * every touch of its API is kept here.
 */
class Bladestan
{
    public function installed(): bool
    {
        return class_exists(BladeToPHPCompiler::class);
    }

    /**
     * The PHPStan config that puts Bladestan's classes in the container:
     * its compiler services and parser override as it ships them, and
     * quine's own copy of its extension list, which leaves out the
     * result-cache extension that discards the whole cache on any Blade
     * edit (resources/phpstan/bladestan.neon says what else is left out).
     *
     * @return list<string>
     */
    public function configFiles(): array
    {
        $file = (new ReflectionClass(BladeToPHPCompiler::class))->getFileName();
        $root = dirname($file === false ? '' : $file, 3);

        return [
            $root.'/config/template-compiler/services.neon',
            $root.'/config/template-compiler/php-parser.neon',
            dirname(__DIR__, 2).'/resources/phpstan/bladestan.neon',
        ];
    }

    /**
     * The templates this call renders, with the types of the variables it hands them.
     *
     * @return list<array{name: string, parameters: array<string, Type>}>
     */
    public function viewCalls(Container $phpstan, CallLike $node, Scope $scope): array
    {
        $matches = match (true) {
            $node instanceof FuncCall, $node instanceof StaticCall => $phpstan->getByType(LaravelViewFunctionMatcher::class)->match($node, $scope),
            $node instanceof MethodCall => $phpstan->getByType(BladeViewMethodsMatcher::class)->match($node, $scope),
            $node instanceof New_ => $phpstan->getByType(MailablesContentMatcher::class)->match($node, $scope),
            default => [],
        };

        $calls = [];

        foreach ($matches as $match) {
            $calls[] = ['name' => $match->templateName, 'parameters' => $match->parametersArray];
        }

        return $calls;
    }

    /**
     * The file a view name resolves to, or null when there is none.
     */
    public function pathOf(Container $phpstan, string $name): ?string
    {
        try {
            return $phpstan->getByType(TemplateFilePathResolver::class)->resolveExistingFilePath($name);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The template compiled to PHP, with the map from PHP line to the blade
     * file and line it came from, and what Bladestan could not do on the way
     * (a syntax error it recovered from by dropping the body, a missing
     * include). Null when the view does not exist.
     *
     * @param  array<string, Type>  $parameters
     * @return array{php: string, lines: array<int, array<string, int>>, errors: list<array{string, string}>}|null errors are [message, identifier]: `bladestan.parsing` means the body was dropped
     */
    public function compile(Container $phpstan, string $name, array $parameters): ?array
    {
        $path = $this->pathOf($phpstan, $name);

        if ($path === null) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $compiled = $phpstan->getByType(BladeToPHPCompiler::class)->compileContent($path, $name, $contents, $parameters);

        return [
            'php' => $compiled->phpFileContents,
            'lines' => $compiled->phpToTemplateLines,
            'errors' => array_map(fn (array $error) => [(string) $error[0], (string) $error[1]], $compiled->errors),
        ];
    }
}
