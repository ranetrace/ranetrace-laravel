<?php

declare(strict_types=1);

use Ranetrace\Laravel\Support\WorkerSchedule;

/*
 * The scheduler's overlap lock is removed when a run finishes. A run that is
 * hard-killed leaves it until it expires, and the default expiry is 24 hours,
 * in which every run of ranetrace:work is skipped and nothing leaves the
 * application. Applications copy the schedule line from these files, so each
 * place that shows it gives the lock an expiry in minutes.
 */
test('every schedule line for ranetrace:work gives its overlap lock an expiry', function (): void {
    $files = [...boostResourceFiles(), dirname(__DIR__, 2).'/README.md'];

    $scheduleLines = [];

    foreach ($files as $file) {
        preg_match_all("/Schedule::command\('ranetrace:work'\).*?;/s", (string) file_get_contents($file), $matches);

        foreach ($matches[0] as $line) {
            $scheduleLines[] = $line;
        }
    }

    expect($scheduleLines)->toHaveCount(3);

    foreach ($scheduleLines as $line) {
        expect($line)->toContain('->withoutOverlapping(5)');
    }
});

test('the line ranetrace:status prints for an unscheduled worker is the documented one', function (): void {
    $files = [...boostResourceFiles(), dirname(__DIR__, 2).'/README.md'];

    $documented = [];

    foreach ($files as $file) {
        preg_match_all("/Schedule::command\('ranetrace:work'\).*?;/s", (string) file_get_contents($file), $matches);

        foreach ($matches[0] as $line) {
            $documented[] = preg_replace('/\s+/', '', $line);
        }
    }

    expect($documented)->toHaveCount(3)
        ->each->toBe(preg_replace('/\s+/', '', WorkerSchedule::DOCUMENTED_LINE));
});
