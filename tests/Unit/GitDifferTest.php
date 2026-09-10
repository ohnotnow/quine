<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Ohffs\Quine\GitDiffer;
use Ohffs\Quine\Project;

it('returns the whole file as added lines when the directory is not a git repository', function () {
    $root = dirname(config()->string('quine.graph_path')).'/not-a-repo';
    File::ensureDirectoryExists($root);
    File::put($root.'/thing.php', "<?php\n\necho 'hi';\n");
    config()->set('quine.base_path', $root);

    $diff = (new GitDiffer(Project::fromConfig()))->diff('thing.php');

    expect($diff)->toBe("+<?php\n+\n+echo 'hi';\n");
});
