<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;
use Ohffs\Quine\Support\AppFiles;
use Ohffs\Quine\Support\Matches;

/**
 * The plain static layer: which app class mentions which other app class.
 * A regex over fully qualified references, so labelled a heuristic; namespace
 * declarations drop out because they never name a class that exists.
 */
final class UsesSource implements Source
{
    public function collect(Project $project, Graph $graph): void
    {
        $pattern = '/(?:^use |[^\w\\\\])('.preg_quote($project->namespace, '/').'[\w\\\\]+)(?:;|::|\s|\()/m';

        foreach (AppFiles::under($project) as ['class' => $from, 'file' => $file, 'path' => $path]) {
            $seen = [];

            foreach (Matches::in($pattern, $file->getContents()) as [$to, $line]) {
                if ($to === $from || isset($seen[$to]) || ! (class_exists($to) || interface_exists($to) || enum_exists($to))) {
                    continue;
                }

                $seen[$to] = true;
                $graph->edge($from, $to, 'uses', 'static reference (heuristic)', "$path:$line");
            }
        }
    }
}
