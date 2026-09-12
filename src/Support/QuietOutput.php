<?php

declare(strict_types=1);

namespace Ohffs\Quine\Support;

use PHPStan\Command\Output;
use PHPStan\Command\OutputStyle;

/**
 * The output PHPStan's result cache manager reports to, which nobody hears:
 * quine never runs verbose, so the manager has nothing to say through it,
 * and PHPStan's own implementation needs the phar's prefixed symfony/console.
 */
final class QuietOutput implements Output, OutputStyle // @phpstan-ignore phpstanApi.interface, phpstanApi.interface
{
    public function writeFormatted(string $message): void {}

    public function writeLineFormatted(string $message): void {}

    public function writeRaw(string $message): void {}

    public function getStyle(): OutputStyle
    {
        return $this;
    }

    public function isVerbose(): bool
    {
        return false;
    }

    public function isVeryVerbose(): bool
    {
        return false;
    }

    public function isDebug(): bool
    {
        return false;
    }

    public function isDecorated(): bool
    {
        return false;
    }

    public function title(string $message): void {}

    public function section(string $message): void {}

    /** @param  list<string>  $elements */
    public function listing(array $elements): void {}

    public function success(string $message): void {}

    public function error(string $message): void {}

    public function warning(string $message): void {}

    public function note(string $message): void {}

    public function caution(string $message): void {}

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function table(array $headers, array $rows): void {}

    public function newLine(int $count = 1): void {}

    public function progressStart(int $max = 0): void {}

    public function progressAdvance(int $step = 1): void {}

    public function progressFinish(): void {}
}
