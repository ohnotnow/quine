<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine;

use Illuminate\Filesystem\Filesystem;

/**
 * @phpstan-type Edge array{from: string, to: string, kind: string, label: string, at: ?string}
 */
final class Graph
{
    /** @var array<string, mixed> */
    public array $meta = [];

    /** @var array<string, mixed> */
    public array $schema = [];

    /** @var array<string, mixed> */
    public array $models = [];

    /** @var list<Edge> */
    public array $edges = [];

    /** @var array<string, mixed> */
    public array $coverage = [];

    /**
     * Add an edge. The graph is a set: an identical edge added twice is kept once.
     */
    public function edge(string $from, string $to, string $kind, string $label, ?string $at): void
    {
        $edge = ['from' => $from, 'to' => $to, 'kind' => $kind, 'label' => $label, 'at' => $at];

        if (! in_array($edge, $this->edges, true)) {
            $this->edges[] = $edge;
        }
    }

    /**
     * @return list<Edge>
     */
    public function edgesFrom(string $node, ?string $kind = null): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (array $edge) => $edge['from'] === $node && ($kind === null || $edge['kind'] === $kind),
        ));
    }

    /**
     * @return list<Edge>
     */
    public function edgesTo(string $node, ?string $kind = null): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (array $edge) => $edge['to'] === $node && ($kind === null || $edge['kind'] === $kind),
        ));
    }

    /**
     * @return array{meta: array<string, mixed>, schema: array<string, mixed>, models: array<string, mixed>, edges: list<Edge>, coverage: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'schema' => $this->schema,
            'models' => $this->models,
            'edges' => $this->edges,
            'coverage' => $this->coverage,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $graph = new self;

        $graph->meta = self::map($data['meta'] ?? []);
        $graph->schema = self::map($data['schema'] ?? []);
        $graph->models = self::map($data['models'] ?? []);
        $graph->coverage = self::map($data['coverage'] ?? []);

        foreach (is_array($data['edges'] ?? null) ? $data['edges'] : [] as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $graph->edge(
                from: (string) ($edge['from'] ?? ''),
                to: (string) ($edge['to'] ?? ''),
                kind: (string) ($edge['kind'] ?? ''),
                label: (string) ($edge['label'] ?? ''),
                at: isset($edge['at']) ? (string) $edge['at'] : null,
            );
        }

        return $graph;
    }

    public function save(string $path): void
    {
        $files = new Filesystem;

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function load(string $path): self
    {
        $data = json_decode((new Filesystem)->get($path), true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
