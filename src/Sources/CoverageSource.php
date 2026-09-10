<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Illuminate\Filesystem\Filesystem;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;

/**
 * Which test files execute which source files, overlaid from pest tia's cache.
 * Only ever as fresh as the last `pest --tia` run, so the graph says plainly
 * which tests on disk the cache has never seen.
 */
final class CoverageSource implements Source
{
    public function collect(Project $project, Graph $graph): void
    {
        $path = $this->locate($project);

        if ($path === null) {
            $graph->meta['coverage'] = 'unavailable';

            return;
        }

        $data = json_decode((new Filesystem)->get($path), true, 512, JSON_THROW_ON_ERROR);
        $files = is_array($data) && is_array($data['files'] ?? null) ? $data['files'] : [];
        $edges = is_array($data) && is_array($data['edges'] ?? null) ? $data['edges'] : [];

        $coverage = [];

        foreach ($edges as $test => $indices) {
            foreach (is_array($indices) ? $indices : [] as $index) {
                $file = is_int($index) ? ($files[$index] ?? null) : null;

                if (is_string($file)) {
                    $coverage[$file][] = (string) $test;
                }
            }
        }

        $missing = array_values(array_filter($this->testFiles($project), fn (string $test) => ! array_key_exists($test, $edges)));

        $graph->coverage = $coverage;
        $graph->meta['coverage'] = $missing === [] ? 'ok' : 'stale';
        $graph->meta['coverage_source'] = $project->relative($path);
        $graph->meta['coverage_missing_tests'] = $missing;
    }

    /**
     * The configured tia graph, or the cache pest keeps under ~/.pest/tia for this project.
     */
    private function locate(Project $project): ?string
    {
        if ($project->tiaGraphPath !== null) {
            return is_file($project->tiaGraphPath) ? $project->tiaGraphPath : null;
        }

        $home = $_SERVER['HOME'] ?? null;
        $matches = glob((is_string($home) ? $home : '').'/.pest/tia/'.basename($project->basePath).'-*/graph.json');

        return $matches === false || $matches === [] ? null : $matches[0];
    }

    /**
     * Every *Test.php under the tests path, relative to the project, sorted.
     *
     * @return list<string>
     */
    private function testFiles(Project $project): array
    {
        $files = new Filesystem;

        if (! $files->isDirectory($project->testsPath)) {
            return [];
        }

        $tests = [];

        foreach ($files->allFiles($project->testsPath) as $file) {
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $tests[] = $project->relative($file->getPathname());
            }
        }

        sort($tests);

        return $tests;
    }
}
