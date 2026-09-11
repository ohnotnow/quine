<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Str;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;

/**
 * Every scheduled task with its cron expression. A scheduled artisan command
 * resolves to the class that runs it, so an edit to that class can be told
 * it runs on a schedule; a closure command or a shell job keeps its name.
 */
final class ScheduleSource implements Source
{
    public function __construct(
        private readonly Schedule $schedule,
        private readonly Kernel $artisan,
    ) {}

    public function collect(Project $project, Graph $graph): void
    {
        foreach ($this->schedule->events() as $event) {
            $graph->edge('schedule', $this->target($event), 'schedule', $event->getExpression(), null);
        }
    }

    /**
     * The command class when the summary names a registered class-based
     * command; otherwise the summary as Laravel itself would display it.
     */
    private function target(Event $event): string
    {
        $summary = $this->summary($event);
        $command = $this->artisan->all()[Str::before($summary, ' ')] ?? null;

        if ($command === null || $command instanceof ClosureCommand) {
            return $summary;
        }

        return $command::class;
    }

    /**
     * An artisan command as it was scheduled ("inspire"), with the php binary,
     * artisan path and output redirection stripped; anything else as Laravel
     * itself would display it.
     */
    private function summary(Event $event): string
    {
        if (is_string($event->command) && $event->command !== '') {
            $command = Str::after(Event::normalizeCommand($event->command), 'artisan ');

            return trim(Str::before($command, ' >'));
        }

        return $event->getSummaryForDisplay();
    }
}
