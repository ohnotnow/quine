<?php

declare(strict_types=1);

it('emits one schedule edge per scheduled task with its cron expression', function () {
    expect(updatedGraph()->edgesFrom('schedule', 'schedule'))->toBe([[
        'from' => 'schedule',
        'to' => 'inspire',
        'kind' => 'schedule',
        'label' => '0 0 * * *',
        'at' => null,
    ]]);
});
