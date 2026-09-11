<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

require_once dirname(__DIR__, 2).'/hooks/claude-code/post-edit.php';

beforeEach(function () {
    $this->root = dirname(config()->string('quine.graph_path')).'/host';
    File::ensureDirectoryExists($this->root.'/vendor/ohffs/quine');
    File::ensureDirectoryExists($this->root.'/database/migrations');
    File::put($this->root.'/artisan', '#!/usr/bin/env php');
    $this->edited = $this->root.'/database/migrations/2026_01_01_000000_create_things_table.php';
    File::put($this->edited, '<?php');
    $this->calls = [];
    $this->exec = function (array $command, string $cwd, int $timeoutSeconds, ?string $stdin = null) {
        $this->calls[] = [$command, $cwd, $timeoutSeconds, $stdin];

        return "some nudge\n";
    };
});

it('hands the edit itself to quine:nudge on stdin', function () {
    QuineHook::run(['tool_input' => ['file_path' => $this->edited, 'old_string' => "a\n", 'new_string' => "b\n"]], $this->exec);
    QuineHook::run(['tool_input' => ['file_path' => $this->edited, 'content' => "<?php\n"]], $this->exec);

    expect($this->calls)->toBe([
        [['php', 'artisan', 'quine:nudge', $this->edited, '--edit'], $this->root, 20, '{"old":"a\\n","new":"b\\n"}'],
        [['php', 'artisan', 'quine:nudge', $this->edited, '--edit'], $this->root, 20, '{"old":null,"new":"<?php\\n"}'],
    ]);
});

it('injects the nudge as additional context when quine has something to say', function () {
    $json = QuineHook::run(['tool_input' => ['file_path' => $this->edited]], $this->exec);

    expect($json)->toBeString();

    $decoded = json_decode((string) $json, true);

    expect($decoded['hookSpecificOutput']['hookEventName'])->toBe('PostToolUse')
        ->and($decoded['hookSpecificOutput']['additionalContext'])->toBe('some nudge')
        ->and($this->calls)->toBe([[['php', 'artisan', 'quine:nudge', $this->edited], $this->root, 20, null]]);
});

it('stays silent when quine prints nothing', function () {
    $exec = fn () => "  \n";

    expect(QuineHook::run(['tool_input' => ['file_path' => $this->edited]], $exec))->toBeNull();
});

it('does nothing for a file with no Laravel root above it', function () {
    $outside = dirname(config()->string('quine.graph_path')).'/elsewhere/file.php';
    File::ensureDirectoryExists(dirname($outside));
    File::put($outside, '<?php');

    expect(QuineHook::run(['tool_input' => ['file_path' => $outside]], $this->exec))->toBeNull()
        ->and($this->calls)->toBe([]);
});

it('treats malformed stdin as nothing to do', function () {
    expect(QuineHook::run(QuineHook::decode('not json at all'), $this->exec))->toBeNull()
        ->and(QuineHook::run(QuineHook::decode(''), $this->exec))->toBeNull()
        ->and($this->calls)->toBe([]);
});
