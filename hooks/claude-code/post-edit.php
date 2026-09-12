#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * quine's Claude Code PostToolUse hook.
 *
 * Claude Code runs this after every Write or Edit, with the tool call as JSON
 * on stdin. It finds the Laravel app the edited file belongs to, asks that
 * app's quine for nudges about the edit, and hands them back to the agent as
 * additional context. It prints nothing unless quine has something to say,
 * says so when quine ran out of time (silence must never mean "broken"), and
 * it never fails an edit.
 *
 * Standalone on purpose: no Laravel bootstrap, no Composer autoload. The app's
 * own artisan does the real work. The edit itself (the text replaced and the
 * text written) goes to quine on stdin, so the nudge is about this edit and
 * not about everything uncommitted in the file.
 */
final class QuineHook
{
    /**
     * Seconds to wait for quine:nudge. A nudge from a saved graph takes well
     * under a second; only a first run with no graph at all builds one.
     */
    public const TIMEOUT = 20;

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $raw): array
    {
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * The JSON to print for Claude Code, or null to stay silent.
     *
     * @param  array<string, mixed>  $input
     * @param  callable(list<string>, string, int, ?string): ?string  $exec  Runs a command in a directory with a timeout and optional stdin, returning stdout, or null when it ran out of time.
     */
    public static function run(array $input, callable $exec): ?string
    {
        try {
            $toolInput = $input['tool_input'] ?? null;
            $file = is_array($toolInput) ? ($toolInput['file_path'] ?? null) : null;

            if (! is_string($file) || $file === '') {
                return null;
            }

            $root = self::laravelRoot(dirname($file));

            if ($root === null || ! is_dir($root.'/vendor/ohffs/quine')) {
                return null;
            }

            $edit = self::edit($toolInput);
            $command = ['php', 'artisan', 'quine:nudge', $file, ...($edit === null ? [] : ['--edit'])];
            $stdout = $exec($command, $root, self::TIMEOUT, $edit);
            $nudges = $stdout === null
                ? 'Quine: gave up after '.self::TIMEOUT.'s waiting for quine:nudge (a first run builds the whole graph). Run php artisan quine:update once by hand; after that an edit answers in well under a second.'
                : trim($stdout);

            if ($nudges === '') {
                return null;
            }

            return json_encode([
                'hookSpecificOutput' => [
                    'hookEventName' => 'PostToolUse',
                    'additionalContext' => $nudges,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The edit as JSON for quine:nudge --edit: what an Edit replaced and
     * wrote, or what a Write wrote with nothing replaced. Null when the tool
     * input carries neither, and quine falls back to git.
     *
     * @param  array<string, mixed>  $toolInput
     */
    private static function edit(array $toolInput): ?string
    {
        if (isset($toolInput['old_string'], $toolInput['new_string']) && is_string($toolInput['old_string']) && is_string($toolInput['new_string'])) {
            return json_encode(['old' => $toolInput['old_string'], 'new' => $toolInput['new_string']], JSON_THROW_ON_ERROR);
        }

        if (isset($toolInput['content']) && is_string($toolInput['content'])) {
            return json_encode(['old' => null, 'new' => $toolInput['content']], JSON_THROW_ON_ERROR);
        }

        return null;
    }

    /**
     * Run a command without a shell, returning its stdout, or null when it
     * ran past the timeout and was killed.
     *
     * @param  list<string>  $command
     */
    public static function exec(array $command, string $cwd, int $timeoutSeconds, ?string $stdin = null): ?string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            return '';
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $timedOut = false;
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);

            if (! proc_get_status($process)['running']) {
                $stdout .= (string) stream_get_contents($pipes[1]);

                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                $timedOut = true;

                break;
            }

            usleep(20000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $timedOut ? null : $stdout;
    }

    /**
     * The nearest directory above the file that holds an artisan script.
     */
    private static function laravelRoot(string $directory): ?string
    {
        while (true) {
            if (is_file($directory.'/artisan')) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $output = QuineHook::run(QuineHook::decode((string) stream_get_contents(STDIN)), [QuineHook::class, 'exec']);

    if ($output !== null) {
        echo $output, PHP_EOL;
    }
}
