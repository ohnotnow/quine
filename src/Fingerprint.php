<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Illuminate\Filesystem\Filesystem;

/**
 * A cheap answer to "has the app's shape changed since the graph was built?":
 * composer.lock, plus the path and mtime of every file under the migrations,
 * routes and app directories.
 */
final class Fingerprint
{
    public static function of(Project $project): string
    {
        $files = new Filesystem;
        $lock = $project->basePath.'/composer.lock';
        $entries = [];

        foreach ([$project->migrationsPath, $project->basePath.'/routes', $project->appPath] as $directory) {
            if (! $files->isDirectory($directory)) {
                continue;
            }

            foreach ($files->allFiles($directory) as $file) {
                $entries[] = $project->relative($file->getPathname()).'@'.$file->getMTime();
            }
        }

        sort($entries);

        return hash('xxh128', ($files->exists($lock) ? $files->get($lock) : '')."\n".implode("\n", $entries));
    }
}
