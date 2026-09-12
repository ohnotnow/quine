<?php

declare(strict_types=1);

namespace Ohffs\Quine\Symbols;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Ohffs\Quine\Project;
use Ohffs\Quine\Support\Bladestan;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Collectors\Registry as Collectors;
use PHPStan\DependencyInjection\Container;
use PHPStan\Rules\DirectRegistry as Rules;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use Throwable;

/**
 * At every call that renders a template, compile the template with the
 * variable types that call hands it and run the member collector over the
 * result, so a template's reads land on its own blade line. The nesting is
 * what Bladestan's own rule does; the difference is the collector registry
 * is not empty.
 *
 * @implements Collector<CallLike, list<array{string, string, string, string, int}>>
 */
final class TemplateCollector implements Collector
{
    /**
     * The templates Bladestan could not compile, by project-relative path
     * (the view name when it resolves to no file), with the error's first line.
     *
     * @var array<string, string>
     */
    public array $failed = [];

    /**
     * The templates Bladestan compiled but could not run the view composers
     * of, so the variables those add are untyped in the rows; same keys.
     *
     * @var array<string, string>
     */
    public array $partial = [];

    /**
     * @param  list<string>  $viewRoots  Every directory a template path can be relative to, longest first.
     */
    public function __construct(
        private readonly Bladestan $bladestan,
        private readonly Container $phpstan,
        private readonly FileAnalyser $analyser,
        private readonly MemberCollector $members,
        private readonly Project $project,
        private readonly array $viewRoots,
    ) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<array{string, string, string, string, int}>|null [from, to, kind, label, line] per row
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        $rows = [];

        foreach ($this->bladestan->viewCalls($this->phpstan, $node, $scope) as ['name' => $name, 'parameters' => $parameters]) {
            try {
                $rows = [...$rows, ...$this->rowsOf($name, $parameters)];
            } catch (Throwable $e) {
                $this->failed[$this->relativeName($name)] = Str::before($e->getMessage(), "\n");
            }
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * The project-relative path of a view, or the view name when it resolves to no file.
     */
    private function relativeName(string $name): string
    {
        $path = $this->bladestan->pathOf($this->phpstan, $name);

        return $path === null ? $name : $this->project->relative($path);
    }

    /**
     * @param  array<string, Type>  $parameters
     * @return list<array{string, string, string, string, int}>
     */
    private function rowsOf(string $name, array $parameters): array
    {
        $compiled = $this->bladestan->compile($this->phpstan, $name, $parameters);

        if ($compiled === null) {
            return [];
        }

        // Bladestan recovers from a syntax error by dropping the template's body and noting it;
        // rows from what is left would be a partial picture presented as the whole. Any other
        // note (a composer it could not call, a missing include) leaves the body readable.
        foreach ($compiled['errors'] as [$message, $identifier]) {
            if ($identifier === 'bladestan.parsing') {
                $this->failed[$this->relativeName($name)] = $message;

                return [];
            }

            $this->partial[$this->relativeName($name)] ??= Str::before($message, ', called in ');
        }

        $types = array_map(fn (Type $type) => $type->describe(VerbosityLevel::typeOnly()), $parameters);
        $file = sys_get_temp_dir().'/quine-template-'.hash('xxh3', $name.json_encode($types)).'.php';
        (new Filesystem)->put($file, $compiled['php']);

        try {
            $result = $this->analyser->analyseFile($file, [$file => true], new Rules([]), new Collectors([$this->members]), null); // @phpstan-ignore phpstanApi.method, phpstanApi.constructor, phpstanApi.constructor
        } finally {
            (new Filesystem)->delete($file);
        }

        $rows = [];

        foreach ($result->getCollectedData() as $byCollector) { // @phpstan-ignore phpstanApi.method
            foreach ($byCollector as $items) {
                foreach ($items as [$to, $kind, $label, $phpLine]) {
                    $at = $this->bladeLine($this->nearestEntry($compiled['lines'], $phpLine));

                    if ($at !== null) {
                        $rows[] = [$at[0], $to, $kind, $label, $at[1]];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * The map only marks the PHP lines where a blade line starts, so a row
     * between two marks belongs to the nearest mark before it, which is how
     * Bladestan places its own errors.
     *
     * @param  array<int, array<string, int>>  $lines
     * @return array<string, int>
     */
    private function nearestEntry(array $lines, int $phpLine): array
    {
        $entry = [];

        foreach ($lines as $mapped => $candidate) {
            if ($mapped > $phpLine) {
                break;
            }

            $entry = $candidate;
        }

        return $entry;
    }

    /**
     * A line-map entry is [file => blade line] with the file relative to
     * whichever view root Bladestan stripped (the finder's paths and hints,
     * so under Livewire v4 `livewire/x.blade.php` arrives as `x.blade.php`);
     * find the root it lives under and return the project-relative path.
     *
     * @param  array<string, int>  $entry
     * @return array{string, int}|null
     */
    private function bladeLine(array $entry): ?array
    {
        foreach ($entry as $relative => $line) {
            foreach ($this->viewRoots as $root) {
                if (is_file("$root/$relative")) {
                    return [$this->project->relative("$root/$relative"), $line];
                }
            }

            return null;
        }

        return null;
    }
}
