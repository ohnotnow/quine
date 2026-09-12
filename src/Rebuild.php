<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Closure;
use Illuminate\Filesystem\Filesystem;

/**
 * Rebuilds the graph in the background when the hook finds it stale or
 * ignorant of a file, one build at a time. Quine's housekeeping is quine's
 * job: nobody is asked to run quine:update.
 */
final readonly class Rebuild
{
    /**
     * Seconds after which a lock is presumed abandoned, whatever its pid says.
     */
    public const int STALE_LOCK = 600;

    /**
     * @var Closure(string): int Starts `php artisan quine:update` detached in the directory and returns its pid, 0 when unknown.
     */
    private Closure $spawn;

    /**
     * @param  ?Closure(string): int  $spawn
     */
    public function __construct(private Project $project, ?Closure $spawn = null)
    {
        $this->spawn = $spawn ?? self::detached(...);
    }

    public function lockPath(): string
    {
        return dirname($this->project->graphPath).'/update.lock';
    }

    /**
     * Start a rebuild unless one is already running. True when one was started.
     */
    public function start(): bool
    {
        if ($this->running()) {
            return false;
        }

        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->lockPath()));
        $files->put($this->lockPath(), json_encode(['pid' => 0, 'started' => time()], JSON_THROW_ON_ERROR));
        $pid = ($this->spawn)($this->project->basePath);
        $files->put($this->lockPath(), json_encode(['pid' => $pid, 'started' => time()], JSON_THROW_ON_ERROR));

        return true;
    }

    /**
     * Whether the lock names a build that is young enough and, when the
     * platform can tell, whose process is still alive.
     */
    private function running(): bool
    {
        $files = new Filesystem;

        if (! $files->isFile($this->lockPath())) {
            return false;
        }

        $lock = json_decode($files->get($this->lockPath()), true);
        $started = is_array($lock) && is_int($lock['started'] ?? null) ? $lock['started'] : 0;
        $pid = is_array($lock) && is_int($lock['pid'] ?? null) ? $lock['pid'] : 0;

        if (time() - $started > self::STALE_LOCK) {
            return false;
        }

        if ($pid > 0 && function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return true;
    }

    /**
     * `php artisan quine:update` in the app, detached from whoever asked,
     * output discarded; the shell echoes the child's pid. Nothing on Windows.
     */
    private static function detached(string $directory): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 0;
        }

        $process = proc_open(['/bin/sh', '-c', 'nohup php artisan quine:update --quiet > /dev/null 2>&1 & echo $!'], [1 => ['pipe', 'w']], $pipes, $directory);

        if (! is_resource($process)) {
            return 0;
        }

        $pid = (int) trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($process);

        return $pid;
    }
}
