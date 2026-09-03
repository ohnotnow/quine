<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Sources;

use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Ohwhatnow\Quine\Graph;
use Ohwhatnow\Quine\Project;

/**
 * Routes to the app classes that handle them, with the middleware they run under.
 */
final class RoutesSource implements Source
{
    public function __construct(private readonly Router $router) {}

    public function collect(Project $project, Graph $graph): void
    {
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $handler = $route->getActionName();

            if (! str_starts_with($handler, $project->namespace)) {
                continue;
            }

            $name = $route->getName();
            $node = 'route '.implode('|', $route->methods()).' /'.$route->uri().($name === null ? '' : " [$name]");
            $method = str_contains($handler, '@') ? Str::after($handler, '@') : 'page';

            $graph->edge($node, Str::before($handler, '@'), 'route', trim($method.' '.implode(',', $route->gatherMiddleware())), null);
        }
    }
}
