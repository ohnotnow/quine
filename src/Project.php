<?php

declare(strict_types=1);

namespace Ohffs\Quine;

final readonly class Project
{
    /**
     * @param  list<string>  $viewPaths
     * @param  list<string>  $fixturePaths
     */
    public function __construct(
        public string $basePath,
        public string $namespace,
        public string $appPath,
        public string $migrationsPath,
        public array $viewPaths,
        public string $testsPath,
        public array $fixturePaths,
        public string $graphPath,
        public ?string $tiaGraphPath,
    ) {}

    public static function fromConfig(): self
    {
        $config = config();

        $views = $config->get('quine.paths.views');
        $tiaGraph = $config->get('quine.tia_graph');

        return new self(
            basePath: $config->string('quine.base_path'),
            namespace: $config->string('quine.namespace'),
            appPath: $config->string('quine.paths.app'),
            migrationsPath: $config->string('quine.paths.migrations'),
            viewPaths: array_values(is_array($views) ? $views : $config->array('view.paths')),
            testsPath: $config->string('quine.paths.tests'),
            fixturePaths: array_values($config->array('quine.paths.fixtures')),
            graphPath: $config->string('quine.graph_path'),
            tiaGraphPath: is_string($tiaGraph) ? $tiaGraph : null,
        );
    }

    /**
     * Strip the base path from an absolute path. A path outside the base path is returned unchanged.
     */
    public function relative(string $absolute): string
    {
        $prefix = $this->normalise($this->basePath).'/';
        $absolute = $this->normalise($absolute);

        return str_starts_with($absolute, $prefix) ? substr($absolute, strlen($prefix)) : $absolute;
    }

    /**
     * Prefix the base path unless the path is already absolute.
     */
    public function absolute(string $relative): string
    {
        $relative = $this->normalise($relative);

        if (str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative) === 1) {
            return $relative;
        }

        return $this->normalise($this->basePath).'/'.$relative;
    }

    private function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
