<?php

declare(strict_types=1);

namespace Ohffs\Quine;

/**
 * What is being edited (a file and its diff) or asked about (a graph node).
 * Every path is relative to the project's base path.
 */
final readonly class Change
{
    /**
     * A method declaration line; the name is the first capture.
     */
    public const string DECLARATION = '/^\s*(?:(?:abstract|final|public|protected|private|static)\s+)*function\s+(\w+)\s*\(/';

    private function __construct(
        public ?string $path,
        public ?string $diff,
        public ?string $node,
    ) {}

    public static function forFile(string $path, string $diff): self
    {
        return new self($path, $diff, null);
    }

    public static function forNode(string $node): self
    {
        return new self(null, null, $node);
    }

    public function isFile(): bool
    {
        return $this->path !== null;
    }

    public function isNode(): bool
    {
        return $this->node !== null;
    }

    /**
     * The lines the diff adds, without their leading plus sign.
     *
     * @return list<string>
     */
    public function addedLines(): array
    {
        return $this->linesMarked('+');
    }

    /**
     * The lines the diff removes, without their leading minus sign.
     *
     * @return list<string>
     */
    public function removedLines(): array
    {
        return $this->linesMarked('-');
    }

    /**
     * Every line inside the diff's hunks, changed or context, without its
     * leading sign. A change is judged by what it sits beside: an edit in the
     * body of a relation method has that method's declaration within the
     * three context lines git prints.
     *
     * @return list<string>
     */
    public function nearbyLines(): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $this->diff ?? '') ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '@@') || str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }

            if (in_array($line[0], [' ', '+', '-'], true)) {
                $lines[] = substr($line, 1);
            }
        }

        return $lines;
    }

    /**
     * Whether any changed line is code rather than a comment or blank. A
     * docblock edit changes nothing anyone else can see.
     */
    public function touchesCode(): bool
    {
        foreach ([...$this->addedLines(), ...$this->linesMarked('-')] as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '' && preg_match('~^(//|#|/\*|\*)~', $trimmed) !== 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a changed line mentions the word: a class basename, a method
     * name, an event name. Word-bounded, so "author" is not "authors".
     */
    public function mentions(string $word): bool
    {
        $pattern = '/\b'.preg_quote($word, '/').'\b/';

        foreach ([...$this->addedLines(), ...$this->linesMarked('-')] as $line) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every method the diff declares on an added line.
     *
     * @return list<string>
     */
    public function addedMethods(): array
    {
        return array_keys($this->declarations($this->addedLines()));
    }

    /**
     * Methods whose declaration the diff removes without declaring again:
     * deleted, or renamed, either way their callers are about to break.
     *
     * @return list<string>
     */
    public function removedMethods(): array
    {
        $before = $this->declarations($this->linesMarked('-'));
        $after = $this->declarations($this->addedLines());

        return array_values(array_filter(array_keys($before), fn (string $name) => ! array_key_exists($name, $after)));
    }

    /**
     * Methods declared on both a removed and an added line, differently: a
     * parameter, type or visibility changed under whoever calls it.
     *
     * @return list<string>
     */
    public function changedMethods(): array
    {
        $before = $this->declarations($this->linesMarked('-'));
        $after = $this->declarations($this->addedLines());

        return array_values(array_filter(
            array_keys($before),
            fn (string $name) => array_key_exists($name, $after) && $after[$name] !== $before[$name],
        ));
    }

    /**
     * Methods whose body the diff edits, from the declaration each hunk
     * header carries after its line numbers, the way git prints it with a
     * function diff driver and EditDiffer prints it always. A diff without
     * that text says nothing about which method was edited.
     *
     * @return list<string>
     */
    public function editedMethods(): array
    {
        $headers = [];

        foreach (preg_split('/\R/', $this->diff ?? '') ?: [] as $line) {
            if (preg_match('/^@@ [^@]*@@ (.+)$/', $line, $match) === 1) {
                $headers[] = $match[1];
            }
        }

        return array_keys($this->declarations($headers));
    }

    /**
     * Method name to its trimmed declaration line, for every declaration in the lines.
     *
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function declarations(array $lines): array
    {
        $found = [];

        foreach ($lines as $line) {
            if (preg_match(self::DECLARATION, $line, $match) === 1) {
                $found[$match[1]] = trim($line);
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function linesMarked(string $sign): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $this->diff ?? '') ?: [] as $line) {
            if (str_starts_with($line, $sign) && ! str_starts_with($line, $sign.$sign.$sign)) {
                $lines[] = substr($line, 1);
            }
        }

        return $lines;
    }
}
