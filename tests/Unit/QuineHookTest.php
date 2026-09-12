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

it('asks quine what the session changed after a Bash command, finding the app from cwd', function () {
    QuineHook::run(['session_id' => 'sess-1', 'cwd' => $this->root, 'tool_name' => 'Bash', 'tool_input' => ['command' => "cat > app/Models/Service.php <<'EOF'"]], $this->exec);

    expect($this->calls)->toBe([[['php', 'artisan', 'quine:nudge', '--session=sess-1'], $this->root, 20, null]]);
});

it('asks the same question after an Edit or Write, finding the app from the file, and sends nothing on stdin', function () {
    QuineHook::run(['session_id' => 'sess-1', 'cwd' => '/somewhere/else', 'tool_input' => ['file_path' => $this->edited, 'old_string' => "a\n", 'new_string' => "b\n"]], $this->exec);
    QuineHook::run(['session_id' => 'sess-1', 'tool_input' => ['file_path' => $this->edited, 'content' => "<?php\n"]], $this->exec);

    expect($this->calls)->toBe([
        [['php', 'artisan', 'quine:nudge', '--session=sess-1'], $this->root, 20, null],
        [['php', 'artisan', 'quine:nudge', '--session=sess-1'], $this->root, 20, null],
    ]);
});

it('does nothing without a session id', function () {
    expect(QuineHook::run(['cwd' => $this->root, 'tool_input' => ['file_path' => $this->edited]], $this->exec))->toBeNull()
        ->and($this->calls)->toBe([]);
});

it('does nothing for a cwd with no Laravel root above it and no file to go by', function () {
    $outside = dirname(config()->string('quine.graph_path')).'/elsewhere';
    File::ensureDirectoryExists($outside);

    expect(QuineHook::run(['session_id' => 'sess-1', 'cwd' => $outside, 'tool_input' => ['command' => 'ls']], $this->exec))->toBeNull()
        ->and($this->calls)->toBe([]);
});

it('injects the nudge as additional context when quine has something to say', function () {
    $json = QuineHook::run(['session_id' => 'sess-1', 'tool_input' => ['file_path' => $this->edited]], $this->exec);

    expect($json)->toBeString();

    $decoded = json_decode((string) $json, true);

    expect($decoded['hookSpecificOutput']['hookEventName'])->toBe('PostToolUse')
        ->and($decoded['hookSpecificOutput']['additionalContext'])->toBe('some nudge')
        ->and($this->calls)->toBe([[['php', 'artisan', 'quine:nudge', '--session=sess-1'], $this->root, 20, null]]);
});

it('stays silent when quine prints nothing', function () {
    $exec = fn () => "  \n";

    expect(QuineHook::run(['session_id' => 'sess-1', 'tool_input' => ['file_path' => $this->edited]], $exec))->toBeNull();
});

it('says so when quine:nudge runs out of time, instead of staying silent', function () {
    $exec = fn () => null;

    $json = QuineHook::run(['session_id' => 'sess-1', 'tool_input' => ['file_path' => $this->edited]], $exec);
    $decoded = json_decode((string) $json, true);

    expect($decoded['hookSpecificOutput']['additionalContext'])
        ->toBe('Quine had nothing in 20s: the first run in an app builds its index. Run php artisan quine:update once (about 20s on a mid-sized app, read-only apart from storage/app/quine); every edit after that answers in under a second.');
});

it('does nothing for a file with no Laravel root above it', function () {
    $outside = dirname(config()->string('quine.graph_path')).'/elsewhere/file.php';
    File::ensureDirectoryExists(dirname($outside));
    File::put($outside, '<?php');

    expect(QuineHook::run(['session_id' => 'sess-1', 'tool_input' => ['file_path' => $outside]], $this->exec))->toBeNull()
        ->and($this->calls)->toBe([]);
});

it('treats malformed stdin as nothing to do', function () {
    expect(QuineHook::run(QuineHook::decode('not json at all'), $this->exec))->toBeNull()
        ->and(QuineHook::run(QuineHook::decode(''), $this->exec))->toBeNull()
        ->and($this->calls)->toBe([]);
});
