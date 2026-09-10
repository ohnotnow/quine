<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Illuminate\Filesystem\Filesystem;
use Larastan\Larastan\Properties\MigrationHelper;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use PHPStan\DependencyInjection\ContainerFactory;
use ReflectionClass;

/**
 * Column types and nullability read straight from the migrations by larastan's
 * migration reader, so no database connection is needed.
 */
final class SchemaSource implements Source
{
    public function collect(Project $project, Graph $graph): void
    {
        defined('LARAVEL_VERSION') || define('LARAVEL_VERSION', app()->version());

        $tempDirectory = sys_get_temp_dir().'/quine-phpstan-'.hash('xxh3', $project->basePath);
        (new Filesystem)->ensureDirectoryExists($tempDirectory);

        $container = (new ContainerFactory($project->basePath))->create(
            tempDirectory: $tempDirectory,
            additionalConfigFiles: [$this->larastanExtension()],
            analysedPaths: [$project->appPath],
            additionalParameters: ['databaseMigrationsPath' => [$project->migrationsPath]],
        );

        foreach ($container->getByType(MigrationHelper::class)->initializeTables() as $table) {
            foreach ($table->columns as $column) {
                $graph->schema[$table->name][$column->name] = [
                    'type' => $column->readableType,
                    'nullable' => $column->nullable,
                ];
            }
        }
    }

    /**
     * Larastan's own extension.neon, located from the class rather than the host's config.
     */
    private function larastanExtension(): string
    {
        $file = (new ReflectionClass(MigrationHelper::class))->getFileName();

        return dirname($file === false ? '' : $file, 3).'/extension.neon';
    }
}
