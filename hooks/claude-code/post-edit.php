#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * quine's Claude Code PostToolUse hook.
 *
 * Claude Code runs this after every tool that can change files (Bash, Write,
 * Edit and their relatives), with the tool call as JSON on stdin. It finds the
 * Laravel app (above the edited file when the tool named one, else above the
 * session's working directory), asks that app's quine what changed since this
 * session last asked, and hands the answer back to the agent as additional
 * context. It prints nothing unless quine has something to say, says so when
 * quine ran out of time (silence must never mean "broken"), and never fails
 * an edit.
 *
 * Standalone on purpose: no Laravel bootstrap, no Composer autoload. The app's
 * own artisan does the real work: quine:nudge --session scans the tree for
 * files changed since its marker and diffs each against the copy it kept, so
 * a heredoc from Bash and an Edit tool call get the same nudge.
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
            $session = $input['session_id'] ?? null;

            if (! is_string($session) || $session === '') {
                return null;
            }

            $root = self::root($input);

            if ($root === null || ! is_dir($root.'/vendor/ohffs/quine')) {
                return null;
            }

            $stdout = $exec(['php', 'artisan', 'quine:nudge', "--session=$session"], $root, self::TIMEOUT, null);
            $nudges = $stdout === null
                ? 'Quine had nothing in '.self::TIMEOUT.'s: the first run in an app builds its index. Run php artisan quine:update once (about 20s on a mid-sized app, read-only apart from storage/app/quine); every edit after that answers in under a second.'
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
     * The Laravel app the call concerns: above the file the tool named when
     * it named one, else above the session's working directory.
     *
     * @param  array<string, mixed>  $input
     */
    private static function root(array $input): ?string
    {
        $toolInput = $input['tool_input'] ?? null;
        $file = is_array($toolInput) ? ($toolInput['file_path'] ?? null) : null;

        if (is_string($file) && $file !== '') {
            return self::laravelRoot(dirname($file));
        }

        $cwd = $input['cwd'] ?? null;

        return is_string($cwd) && $cwd !== '' ? self::laravelRoot($cwd) : null;
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
