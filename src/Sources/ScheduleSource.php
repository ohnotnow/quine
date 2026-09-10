<?php

declare(strict_types=1);

namespace Ohffs\Quine\Sources;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Ohffs\Quine\Graph;
use Ohffs\Quine\Project;

/**
 * Every scheduled task with its cron expression.
 */
final class ScheduleSource implements Source
{
    public function __construct(private readonly Schedule $schedule) {}

    public function collect(Project $project, Graph $graph): void
    {
        foreach ($this->schedule->events() as $event) {
            $graph->edge('schedule', $this->summary($event), 'schedule', $event->getExpression(), null);
        }
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
