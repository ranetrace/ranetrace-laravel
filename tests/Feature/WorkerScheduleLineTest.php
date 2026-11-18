<?php

declare(strict_types=1);

use Ranetrace\Laravel\Support\WorkerSchedule;

/**
 * Every schedule line for ranetrace:work in the Boost resources and the README,
 * as written there.
 *
 * @return list<string>
 */
function documentedWorkerScheduleLines(): array
{
    $lines = [];

    foreach ([...boostResourceFiles(), dirname(__DIR__, 2).'/README.md'] as $file) {
        preg_match_all("/Schedule::command\('ranetrace:work'\).*?;/s", (string) file_get_contents($file), $matches);

        foreach ($matches[0] as $line) {
            $lines[] = $line;
        }
    }

    return $lines;
}

/*
 * The scheduler's overlap lock is removed when a run finishes. A run that is
 * hard-killed leaves it until it expires, and the default expiry is 24 hours,
 * in which every run of ranetrace:work is skipped and nothing leaves the
 * application. Applications copy the schedule line from these files, so each
 * place that shows it gives the lock an expiry in minutes.
 */
test('every documented schedule line for ranetrace:work is the one ranetrace:status prints, and its overlap lock expires', function (): void {
    $withoutWhitespace = static fn (string $line): string => (string) preg_replace('/\s+/', '', $line);

    expect(array_map($withoutWhitespace, documentedWorkerScheduleLines()))->toHaveCount(3)
        ->each->toBe($withoutWhitespace(WorkerSchedule::DOCUMENTED_LINE));

    expect(WorkerSchedule::DOCUMENTED_LINE)->toContain('->withoutOverlapping(5)');
});
