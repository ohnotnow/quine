<?php

declare(strict_types=1);

namespace Ohffs\Quine;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * What one editing session has already seen: when the hook last looked, and
 * each changed file as it stood then, so the next nudge is about the change
 * just made and not everything since the last commit.
 */
final readonly class Session
{
    private string $directory;

    public function __construct(Project $project, string $id)
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';

        if ($clean === '' || $clean !== $id) {
            throw new InvalidArgumentException("Session id may only hold letters, digits, - and _: $id");
        }

        $this->directory = dirname($project->graphPath).'/sessions/'.$clean;
    }

    /**
     * When this session's previous run started, or null on the first run.
     */
    public function marker(): ?int
    {
        $files = new Filesystem;

        return $files->isFile("$this->directory/marker") ? (int) $files->get("$this->directory/marker") : null;
    }

    public function touch(int $time): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->directory);
        $files->put("$this->directory/marker", (string) $time);
    }

    /**
     * The file as this session last saw it, or null when it never has.
     */
    public function snapshot(string $relativePath): ?string
    {
        $files = new Filesystem;
        $path = $this->snapshotPath($relativePath);

        return $files->isFile($path) ? $files->get($path) : null;
    }

    public function remember(string $relativePath, string $content): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists("$this->directory/snapshots");
        $files->put($this->snapshotPath($relativePath), $content);
    }

    /**
     * The files this session has already been told the graph does not know.
     *
     * @return list<string>
     */
    public function announced(): array
    {
        $files = new Filesystem;
        $path = "$this->directory/announced";

        return $files->isFile($path) ? array_values(array_filter(explode("\n", $files->get($path)), fn (string $line) => $line !== '')) : [];
    }

    /**
     * @param  list<string>  $relativePaths
     */
    public function announce(array $relativePaths): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->directory);
        $files->put("$this->directory/announced", implode("\n", [...$this->announced(), ...$relativePaths])."\n");
    }

    private function snapshotPath(string $relativePath): string
    {
        return "$this->directory/snapshots/".hash('xxh128', $relativePath);
    }
}
