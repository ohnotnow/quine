#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * quine's Claude Code PostToolUse hook.
 *
 * Claude Code runs this after every Write or Edit, with the tool call as JSON
 * on stdin. It finds the Laravel app the edited file belongs to, asks that
 * app's quine for nudges about the edit, and hands them back to the agent as
 * additional context. It prints nothing at all unless a recipe has something
 * to say, and it never fails an edit.
 *
 * Standalone on purpose: no Laravel bootstrap, no Composer autoload. The app's
 * own artisan does the real work.
 */
final class QuineHook
{
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
     * @param  callable(list<string>, string, int): string  $exec  Runs a command in a directory with a timeout, returning stdout.
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

            $nudges = trim($exec(['php', 'artisan', 'quine:nudge', $file], $root, 20));

            if ($nudges === '') {
                return null;
            }

            return json_encode([
                'hookSpecificOutput' => [
                    'hookEventName' => 'PostToolUse',
                    'additionalContext' => "quine: hang on.\n".$nudges,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run a command without a shell, giving up after the timeout.
     *
     * @param  list<string>  $command
     */
    public static function exec(array $command, string $cwd, int $timeoutSeconds): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            return '';
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
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

                break;
            }

            usleep(20000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout;
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
