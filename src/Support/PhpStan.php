<?php

declare(strict_types=1);

namespace Ohffs\Quine\Support;

use Illuminate\Filesystem\Filesystem;
use Larastan\Larastan\Properties\MigrationHelper;
use Ohffs\Quine\Project;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Parser\PathRoutingParser;
use ReflectionClass;

/**
 * A PHPStan container with larastan loaded, built in-process against the
 * app that is already booted. Building it never runs an extension's
 * bootstrap files, which is what lets the app stay the one artisan booted.
 */
final class PhpStan
{
    /**
     * @param  list<string>  $analysedPaths
     * @param  list<string>  $extraConfigFiles
     * @param  array<string, mixed>  $additionalParameters
     */
    public static function container(Project $project, array $analysedPaths, array $extraConfigFiles = [], array $additionalParameters = []): Container
    {
        defined('LARAVEL_VERSION') || define('LARAVEL_VERSION', app()->version());

        $tempDirectory = sys_get_temp_dir().'/quine-phpstan-'.hash('xxh3', $project->basePath);
        (new Filesystem)->ensureDirectoryExists($tempDirectory);

        return (new ContainerFactory($project->basePath))->create(
            tempDirectory: $tempDirectory,
            additionalConfigFiles: [self::larastanExtension(), ...$extraConfigFiles],
            analysedPaths: $analysedPaths,
            additionalParameters: $additionalParameters,
        );
    }

    /**
     * The file analyser, told which files count as analysed. PHPStan's commands
     * do this before every run; without it the routing parser hands every file
     * to the simple parser and no collector is ever visited.
     *
     * @param  list<string>  $files  Absolute paths.
     */
    public static function analyser(Container $container, array $files): FileAnalyser
    {
        $parser = $container->getService('pathRoutingParser');

        if ($parser instanceof PathRoutingParser) { // @phpstan-ignore phpstanApi.class
            $parser->setAnalysedFiles($files); // @phpstan-ignore phpstanApi.method
        }

        $container->getByType(NodeScopeResolver::class)->setAnalysedFiles($files);

        return $container->getByType(FileAnalyser::class); // @phpstan-ignore phpstanApi.classConstant
    }

    /**
     * Larastan's own extension.neon, located from the class rather than the host's config.
     */
    private static function larastanExtension(): string
    {
        $file = (new ReflectionClass(MigrationHelper::class))->getFileName();

        return dirname($file === false ? '' : $file, 3).'/extension.neon';
    }
}
