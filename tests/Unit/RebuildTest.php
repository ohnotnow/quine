<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Ohffs\Quine\Project;
use Ohffs\Quine\Rebuild;

beforeEach(function () {
    $this->project = app(Project::class);
    $this->spawned = 0;
    $this->rebuild = new Rebuild($this->project, function (): int {
        $this->spawned++;

        return 4242;
    });
    $this->lock = dirname($this->project->graphPath).'/update.lock';
});

it('spawns a rebuild and writes the lock when none exists', function () {
    expect($this->rebuild->start())->toBeTrue()
        ->and($this->spawned)->toBe(1)
        ->and(File::isFile($this->lock))->toBeTrue()
        ->and(json_decode(File::get($this->lock), true))->toMatchArray(['pid' => 4242]);
});

it('does not spawn a second rebuild while a fresh lock holds a live pid', function () {
    File::ensureDirectoryExists(dirname($this->lock));
    File::put($this->lock, json_encode(['pid' => getmypid(), 'started' => time()]));

    expect($this->rebuild->start())->toBeFalse()
        ->and($this->spawned)->toBe(0);
});

it('spawns again when the lock is older than ten minutes, whatever its pid says', function () {
    File::ensureDirectoryExists(dirname($this->lock));
    File::put($this->lock, json_encode(['pid' => getmypid(), 'started' => time() - 601]));

    expect($this->rebuild->start())->toBeTrue()
        ->and($this->spawned)->toBe(1);
});

it('spawns again when the lock names a pid that is no longer running', function () {
    File::ensureDirectoryExists(dirname($this->lock));
    File::put($this->lock, json_encode(['pid' => 999999, 'started' => time()]));

    expect($this->rebuild->start())->toBeTrue()
        ->and($this->spawned)->toBe(1);
});
