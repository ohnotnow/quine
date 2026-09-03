<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Ohwhatnow\Quine\Project;
use Symfony\Component\Finder\SplFileInfo;

final class AppFiles
{
    /**
     * Every PHP file under a directory of the app, with the class name its path implies
     * (PSR-4 from the configured namespace) and its path relative to the project.
     *
     * @return list<array{class: string, file: SplFileInfo, path: string}>
     */
    public static function under(Project $project, string $directory = ''): array
    {
        $files = new Filesystem;
        $root = rtrim($project->appPath.'/'.$directory, '/');

        if (! $files->isDirectory($root)) {
            return [];
        }

        $prefix = $project->namespace.($directory === '' ? '' : str_replace('/', '\\', $directory).'\\');
        $found = [];

        foreach ($files->allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $found[] = [
                'class' => $prefix.str_replace(['/', '\\'], '\\', Str::before($file->getRelativePathname(), '.php')),
                'file' => $file,
                'path' => $project->relative($file->getPathname()),
            ];
        }

        return $found;
    }
}
