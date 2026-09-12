<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Ohffs\Quine\Project;
use Ohffs\Quine\Session;

it('has no marker or snapshots until told, and keeps both under the graph directory', function () {
    $project = app(Project::class);
    $session = new Session($project, 'sess-1');

    expect($session->marker())->toBeNull()
        ->and($session->snapshot('app/Models/X.php'))->toBeNull();

    $session->touch(1_700_000_000);
    $session->remember('app/Models/X.php', "<?php\n");

    expect($session->marker())->toBe(1_700_000_000)
        ->and($session->snapshot('app/Models/X.php'))->toBe("<?php\n")
        ->and($session->snapshot('app/Models/Y.php'))->toBeNull()
        ->and(File::isDirectory(dirname($project->graphPath).'/sessions/sess-1/snapshots'))->toBeTrue();
});

it('refuses a session id that would leave its directory', function () {
    new Session(app(Project::class), '../x');
})->throws(InvalidArgumentException::class);
