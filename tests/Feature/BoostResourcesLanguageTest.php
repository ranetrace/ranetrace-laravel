<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Pest\Plugins\Tia\Recorder;
use Pest\Support\Container;

/*
 * The Boost guideline and the shipped skills are agent-facing prose: Boost
 * surfaces them into every consuming project, where an agent reads them before
 * it touches the package. The house writing rule against the em-dash (the
 * app's management/keeping-it-human.md) covers them exactly as it covers the
 * diagnostics dashboard, and nothing renders them through a view where a
 * rendered-page sweep would catch the character, which is why they get a
 * ratchet of their own. The whole tree was swept clean in September 2026; this
 * pins it so the next edit cannot bring the dash back unnoticed.
 */

/**
 * Every file under resources/boost, as sorted absolute paths. Swept
 * recursively rather than as a list of the skills that exist today, so a skill
 * added later is covered the day it lands.
 *
 * TIA: the files are read as text, never executed, so the coverage driver
 * records no edge to them. Each one is linked by hand, or a TIA run replays
 * this sweep's cached pass while the prose it guards changes underneath it.
 * Outside a TIA run the recorder is inactive and the link is a no-op.
 *
 * @return list<string>
 */
function boostResourceFiles(): array
{
    $recorder = Container::getInstance()->get(Recorder::class);

    $paths = [];

    foreach (File::allFiles(dirname(__DIR__, 2).'/resources/boost') as $file) {
        $recorder->linkSource($file->getPathname());

        $paths[] = $file->getPathname();
    }

    sort($paths);

    return $paths;
}

test('it enumerates the guideline and every shipped skill', function (): void {
    $names = array_map(
        fn (string $path): string => mb_substr($path, mb_strlen(dirname(__DIR__, 2).'/resources/boost/')),
        boostResourceFiles(),
    );

    expect($names)
        ->toContain('guidelines/core.blade.php')
        ->toContain('skills/ranetrace-error-tracking/SKILL.md')
        ->toContain('skills/ranetrace-worker/SKILL.md');
});

test('no Boost resource carries an em-dash', function (): void {
    $violations = [];

    foreach (boostResourceFiles() as $path) {
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $lineNumber => $line) {
            if (str_contains($line, "\u{2014}")) {
                $violations[] = basename(dirname($path)).'/'.basename($path).':'.($lineNumber + 1).': '.mb_trim($line);
            }
        }
    }

    expect($violations)->toBe(
        [],
        "The Boost guideline and skills speak to agents the way the product speaks to a user, so they carry no em-dash:\n".implode("\n", $violations),
    );
});
