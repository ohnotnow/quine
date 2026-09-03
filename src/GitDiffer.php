<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Throwable;

final readonly class GitDiffer implements Differ
{
    public function __construct(private Project $project) {}

    public function diff(string $relativePath): string
    {
        try {
            $tracked = Process::path($this->project->basePath)->run(['git', 'ls-files', '--error-unmatch', '--', $relativePath]);

            if ($tracked->successful()) {
                return Process::path($this->project->basePath)->run(['git', 'diff', '--no-color', '--', $relativePath])->output();
            }
        } catch (Throwable) {
            // No git, or nothing that behaves like it: fall through to the whole file.
        }

        return $this->wholeFile($relativePath);
    }

    /**
     * Every line of the file prefixed with +, so a recipe sees it all as new.
     */
    private function wholeFile(string $relativePath): string
    {
        $files = new Filesystem;
        $absolute = $this->project->absolute($relativePath);

        if (! $files->exists($absolute)) {
            return '';
        }

        $lines = preg_split('/\R/', $files->get($absolute)) ?: [];

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode('', array_map(fn (string $line) => "+$line\n", $lines));
    }
}
