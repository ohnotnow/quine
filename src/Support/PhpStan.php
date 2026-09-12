<?php

declare(strict_types=1);

namespace Ohffs\Quine\Support;

use Illuminate\Filesystem\Filesystem;
use Larastan\Larastan\Properties\MigrationHelper;
use Ohffs\Quine\Project;
use Ohffs\Quine\Symbols\MemberCollector;
use Ohffs\Quine\Symbols\TemplateCollector;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\ResultCache\ResultCache;
use PHPStan\Analyser\ResultCache\ResultCacheManager;
use PHPStan\Analyser\ResultCache\ResultCacheManagerFactory;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Parser\PathRoutingParser;
use ReflectionClass;
use ReflectionProperty;

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
     * PHPStan's result cache, which hands back the collected rows of every
     * file whose content (and whose dependencies' signatures) did not change
     * since the last build, so a rebuild after one edit analyses one file.
     *
     * The cache file is set on the manager, not passed as a container
     * parameter: PHPStan compiles one container class per distinct parameter
     * set, and a per-app (in tests, per-test) path there meant a fresh
     * 10,000-line class loaded for every build in a process.
     */
    public static function resultCache(Container $container, Project $project): ResultCacheManager
    {
        $manager = $container->getByType(ResultCacheManagerFactory::class)->create([]); // @phpstan-ignore phpstanApi.classConstant, phpstanApi.method
        (new ReflectionProperty($manager, 'cacheFilePath'))->setValue($manager, self::resultCachePath($project));

        return $manager;
    }

    /**
     * The restored cache with more files marked for analysis: PHPStan only
     * replaces the cached rows of files on that list, so a file quine wants
     * re-read for its own reasons (a template it renders changed) has to be
     * added there, not just analysed.
     *
     * @param  list<string>  $files
     */
    public static function alsoAnalysing(ResultCache $restored, array $files): ResultCache
    {
        if ($files === []) {
            return $restored;
        }

        return new ResultCache( // @phpstan-ignore phpstanApi.constructor
            array_values(array_unique([...$restored->getFilesToAnalyse(), ...$files])), // @phpstan-ignore phpstanApi.method
            $restored->isFullAnalysis(), // @phpstan-ignore phpstanApi.method
            $restored->getFullAnalysisReason(), // @phpstan-ignore phpstanApi.method
            $restored->getLastFullAnalysisTime(), // @phpstan-ignore phpstanApi.method
            $restored->getMeta(), // @phpstan-ignore phpstanApi.method
            $restored->getErrors(), // @phpstan-ignore phpstanApi.method
            $restored->getLocallyIgnoredErrors(), // @phpstan-ignore phpstanApi.method
            $restored->getLinesToIgnore(), // @phpstan-ignore phpstanApi.method
            $restored->getUnmatchedLineIgnores(), // @phpstan-ignore phpstanApi.method
            $restored->getCollectedData(), // @phpstan-ignore phpstanApi.method
            $restored->getDependencies(), // @phpstan-ignore phpstanApi.method
            $restored->getUsedTraitDependencies(), // @phpstan-ignore phpstanApi.method
            $restored->getPackageDependencies(), // @phpstan-ignore phpstanApi.method
            $restored->getExportedNodes(), // @phpstan-ignore phpstanApi.method
            $restored->getProjectExtensionFiles(), // @phpstan-ignore phpstanApi.method
            $restored->getCurrentFileHashes(), // @phpstan-ignore phpstanApi.method
        );
    }

    /**
     * The cache lives beside the graph, one file per app, named by the
     * collectors' own source so a change to what quine collects starts a
     * fresh cache instead of serving rows the old collector made. Older
     * caches in the directory are removed.
     */
    public static function resultCachePath(Project $project): string
    {
        $files = new Filesystem;
        $directory = dirname($project->absolute($project->graphPath)).'/phpstan';
        $files->ensureDirectoryExists($directory);

        $sources = array_map(fn (string $class) => (string) $files->get((string) (new ReflectionClass($class))->getFileName()), [MemberCollector::class, TemplateCollector::class]);
        $path = $directory.'/result-cache-'.hash('xxh3', implode("\0", $sources)).'.php';

        foreach ($files->glob($directory.'/result-cache-*.php') as $stale) {
            if ($stale !== $path) {
                $files->delete($stale);
            }
        }

        return $path;
    }

    /**
     * Forget a container once its build is done. PHPStan keeps the handlers
     * it resolved for each container in two static registries keyed by the
     * container's object id and never removes them, which pins the container
     * and everything it reflected. One process building one graph never
     * notices; a test suite building one per test runs out of memory.
     */
    public static function release(Container $container): void
    {
        $id = spl_object_id($container);

        foreach ([['PHPStan\\Analyser\\ExprHandlerRegistry', 'exprHandlersByClass'], ['PHPStan\\Analyser\\StmtHandlerRegistry', 'stmtHandlersByClass']] as [$class, $property]) {
            if (! class_exists($class) || ! property_exists($class, $property)) {
                continue;
            }

            $registry = new ReflectionProperty($class, $property);
            $entries = $registry->getValue();

            if (is_array($entries)) {
                unset($entries[$id]);
                $registry->setValue(null, $entries);
            }
        }
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
