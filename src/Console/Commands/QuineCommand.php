<?php

declare(strict_types=1);

namespace Ohwhatnow\Quine\Console\Commands;

use Illuminate\Console\Command;

class QuineCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'quine:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package quine.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Quine placeholder command executed.');

        return self::SUCCESS;
    }
}
