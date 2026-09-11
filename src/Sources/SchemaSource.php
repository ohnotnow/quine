<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Larastan\Larastan\Properties\MigrationHelper;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use Ohffs\Quine\Support\PhpStan;

/**
 * Column types and nullability read straight from the migrations by larastan's
 * migration reader, so no database connection is needed.
 */
final class SchemaSource implements Source
{
    public function collect(Project $project, Graph $graph): void
    {
        $container = PhpStan::container($project, [$project->appPath], additionalParameters: ['databaseMigrationsPath' => [$project->migrationsPath]]);

        foreach ($container->getByType(MigrationHelper::class)->initializeTables() as $table) {
            foreach ($table->columns as $column) {
                $graph->schema[$table->name][$column->name] = [
                    'type' => $column->readableType,
                    'nullable' => $column->nullable,
                ];
            }
        }
    }
}
