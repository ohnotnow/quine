<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Ohffs\Quine\Fingerprint;
use Ohffs\Quine\Project;

beforeEach(function () {
    $this->root = dirname(config()->string('quine.graph_path')).'/project';
    File::ensureDirectoryExists($this->root.'/app');
    File::ensureDirectoryExists($this->root.'/database/migrations');
    File::put($this->root.'/composer.lock', '{"packages": []}');
    File::put($this->root.'/app/Thing.php', '<?php');

    config()->set('quine.base_path', $this->root);
    config()->set('quine.paths.app', $this->root.'/app');
    config()->set('quine.paths.migrations', $this->root.'/database/migrations');
});

it('is stable when nothing changes', function () {
    $project = Project::fromConfig();

    expect(Fingerprint::of($project))->toBe(Fingerprint::of($project))->toHaveLength(32);
});

it('changes when composer.lock changes', function () {
    $project = Project::fromConfig();
    $before = Fingerprint::of($project);

    File::put($this->root.'/composer.lock', '{"packages": ["x"]}');

    expect(Fingerprint::of($project))->not->toBe($before);
});
