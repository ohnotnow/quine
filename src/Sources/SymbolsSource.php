<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Illuminate\Contracts\View\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\FileViewFinder;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use Ohffs\Quine\Support\AppFiles;
use Ohffs\Quine\Support\Bladestan;
use Ohffs\Quine\Support\PhpStan;
use Ohffs\Quine\Symbols\MemberCollector;
use Ohffs\Quine\Symbols\TemplateCollector;
use PHPStan\Collectors\Registry as Collectors;
use PHPStan\Rules\DirectRegistry as Rules;

/**
 * Every resolved consumer of an app-class member, found by running PHPStan
 * with larastan over the app and test files in-process. The edges point at
 * `Class::member` nodes so "who reads Comment->author" is a lookup.
 */
final class SymbolsSource implements Source
{
    public function __construct(
        private readonly Bladestan $bladestan,
        private readonly Factory $views,
    ) {}

    public function collect(Project $project, Graph $graph): void
    {
        $files = [];

        foreach (AppFiles::under($project) as ['class' => $class, 'file' => $file, 'path' => $path]) {
            $files[$file->getPathname()] = ['from' => $class, 'path' => $path];
        }

        // A test is a node by its path, the way the coverage overlay names it.
        foreach ($this->testFiles($project) as $absolute) {
            $path = $project->relative($absolute);
            $files[$absolute] = ['from' => $path, 'path' => $path];
        }

        $all = array_keys($files);
        $bladestan = $this->bladestan->installed();
        $container = PhpStan::container($project, [$project->appPath, $project->testsPath], $bladestan ? $this->bladestan->configFiles() : [], ['databaseMigrationsPath' => [$project->migrationsPath]]);
        $analyser = PhpStan::analyser($container, $all);
        $members = new MemberCollector($project->namespace, $project->appPath);
        $templates = $bladestan ? new TemplateCollector($this->bladestan, $container, $analyser, $members, $project, $this->viewRoots($project)) : null;
        $rules = new Rules([]); // @phpstan-ignore phpstanApi.constructor
        $collectors = new Collectors($templates === null ? [$members] : [$members, $templates]); // @phpstan-ignore phpstanApi.constructor
        $analysed = array_fill_keys($all, true);

        $counts = ['calls' => 0, 'fetches' => 0, 'sinks' => 0];
        $seen = [];

        foreach ($files as $absolute => ['from' => $from, 'path' => $path]) {
            $result = $analyser->analyseFile($absolute, $analysed, $rules, $collectors, null); // @phpstan-ignore phpstanApi.method

            foreach ($result->getCollectedData() as $byCollector) { // @phpstan-ignore phpstanApi.method
                foreach ($byCollector as $collector => $collectedList) {
                    $fromTemplate = $collector === TemplateCollector::class;

                    foreach ($collectedList as $collected) {
                        // The member collector yields one row per node; the template
                        // collector yields a batch per rendering call, each row naming its template.
                        foreach ($fromTemplate ? $collected : [$collected] as $row) {
                            // App code is recorded from its enclosing method; a test or a template from its path.
                            [$node, $at, $to, $kind, $label] = $fromTemplate
                                ? [$row[0], "{$row[0]}:{$row[4]}", $row[1], $row[2], $row[3]]
                                : [$from === $path || $row[4] === null ? $from : "$from::{$row[4]}", "$path:{$row[3]}", $row[0], $row[1], $row[2]];

                            // A member consuming itself (recursion) says nothing; a member consuming
                            // another member of its own class is a hop the walk needs.
                            if ($to === $node) {
                                continue;
                            }

                            // A read that builds a cache key, a storage path, a URL: the sink joins the label's bracket.
                            $sink = $fromTemplate ? null : $members->sinkAt($absolute, $row[5]);

                            if ($sink !== null) {
                                $label = str_ends_with($label, ')') ? substr($label, 0, -1).", $sink)" : "$label ($sink)";
                                $counts['sinks']++;
                            }

                            $graph->edge($node, $to, $kind, $label, $at);
                            $counts[$kind]++;

                            if ($fromTemplate) {
                                $seen[$node] = true;
                            }
                        }
                    }
                }
            }
        }

        $graph->meta['symbols'] = [
            ...$counts,
            'files' => count($files),
            'bladestan' => $bladestan,
            'templates' => count($seen),
            'templates_failed' => $templates === null ? 0 : count(array_unique($templates->failed)),
        ];
    }

    /**
     * Every directory Bladestan may have stripped from a template path in its
     * line map: the finder's paths and namespace hints, the configured view
     * paths and the base path, longest first so the most specific root wins.
     *
     * @return list<string>
     */
    private function viewRoots(Project $project): array
    {
        $finder = $this->views->getFinder();
        $roots = [...$project->viewPaths, $project->basePath];

        if ($finder instanceof FileViewFinder) {
            $roots = [...$roots, ...$finder->getPaths()];

            foreach ($finder->getHints() as $paths) {
                $roots = [...$roots, ...$paths];
            }
        }

        $roots = array_values(array_unique(array_map(fn (string $root) => rtrim($root, '/'), $roots)));
        usort($roots, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        return $roots;
    }

    /**
     * Every PHP file under the tests path, recursively.
     *
     * @return list<string>
     */
    private function testFiles(Project $project): array
    {
        $files = new Filesystem;

        if (! $files->isDirectory($project->testsPath)) {
            return [];
        }

        $found = [];

        foreach ($files->allFiles($project->testsPath) as $file) {
            if ($file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}
