<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Ohffs\Quine\Graph;

it('answers a second build from the PHPStan result cache, analysing nothing when nothing changed', function () {
    $first = updatedGraph();
    $second = updatedGraph();

    $symbolEdges = function (Graph $graph): array {
        $edges = array_values(array_filter($graph->edges, fn (array $edge) => in_array($edge['kind'], ['calls', 'fetches'], true)));
        usort($edges, fn (array $a, array $b) => strcmp(json_encode($a), json_encode($b)));

        return $edges;
    };

    expect($first->meta['symbols']['analysed'])->toBe($first->meta['symbols']['files'])
        ->and($second->meta['symbols']['analysed'])->toBe(0)
        ->and($symbolEdges($second))->toBe($symbolEdges($first));
});

it('re-analyses the file that renders a template when only the template changed since the cache was written', function () {
    $this->withTemplates();

    // A private copy of the fixture template shadows the workbench one, so the edit touches no shared file.
    $views = sys_get_temp_dir().'/quine-views-'.uniqid();
    File::ensureDirectoryExists("$views/posts");
    $views = (string) realpath($views);
    File::copy(config()->string('quine.paths.views.0').'/posts/comments.blade.php', "$views/posts/comments.blade.php");
    $this->app['view']->getFinder()->prependLocation($views);
    config()->set('quine.paths.views', [$views, config()->string('quine.paths.views.0')]);

    $template = "$views/posts/comments.blade.php";
    $bodyReads = fn (Graph $graph) => array_column(array_filter($graph->edgesTo('Workbench\App\Models\Comment::body'), fn (array $edge) => $edge['from'] === $template), 'label', 'at');

    expect($bodyReads(updatedGraph()))->toBe([]);

    File::append($template, "\n<p>{{ \$comment->body }}</p>\n");
    touch($template, time() + 5);

    $second = updatedGraph();

    // Only the file that renders the template is re-read; PHPStan's cache serves the rest.
    expect($bodyReads($second))->toBe(["$template:14" => 'fetches body'])
        ->and($second->meta['symbols']['analysed'])->toBeGreaterThan(0)
        ->and($second->meta['symbols']['analysed'])->toBeLessThan($second->meta['symbols']['files'] / 2);

    File::deleteDirectory($views);
});
