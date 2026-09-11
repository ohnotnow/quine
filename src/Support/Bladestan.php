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
     * Bladestan's extension config plus its parser override, located from the class.
     *
     * @return list<string>
     */
    public function configFiles(): array
    {
        $file = (new ReflectionClass(BladeToPHPCompiler::class))->getFileName();
        $root = dirname($file === false ? '' : $file, 3);

        return [$root.'/config/extension.neon', $root.'/config/template-compiler/php-parser.neon'];
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
     * The template compiled to PHP, with the map from PHP line to the blade
     * file and line it came from. Null when the view does not exist.
     *
     * @param  array<string, Type>  $parameters
     * @return array{php: string, lines: array<int, array<string, int>>}|null
     */
    public function compile(Container $phpstan, string $name, array $parameters): ?array
    {
        try {
            $path = $phpstan->getByType(TemplateFilePathResolver::class)->resolveExistingFilePath($name);
        } catch (InvalidArgumentException) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $compiled = $phpstan->getByType(BladeToPHPCompiler::class)->compileContent($path, $name, $contents, $parameters);

        return ['php' => $compiled->phpFileContents, 'lines' => $compiled->phpToTemplateLines];
    }
}
