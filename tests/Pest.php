<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Pest\Plugins\Tia\Recorder;
use Pest\Support\Container;
use Ranetrace\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Browser', 'Contract', 'Feature', 'Unit');

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

    foreach (File::allFiles(dirname(__DIR__).'/resources/boost') as $file) {
        $recorder->linkSource($file->getPathname());

        $paths[] = $file->getPathname();
    }

    sort($paths);

    return $paths;
}

/**
 * Every comment that survived into rendered output, as the lines carrying them.
 *
 * The views under resources/views are what this package sends to a browser, and
 * the notes explaining them are written for whoever maintains them, not for the
 * visitor paying for the bytes on a phone. Blade comments keep those notes in the
 * source and out of the output, so anything found here is either a comment
 * written in the wrong syntax or a Blade comment whose braces were mangled on the
 * way in, which renders as page text.
 *
 * @return list<string>
 */
function commentsIn(string $rendered): array
{
    $found = [];

    foreach (explode("\n", $rendered) as $line) {
        $markers = array_filter(
            ['/*', '<!--', '{--', '--}'],
            static fn (string $marker): bool => str_contains($line, $marker),
        );

        // A bare "//" match would flag every https:// in the output, so a line only
        // counts as a line comment when the slashes start it.
        if ($markers !== [] || preg_match('#^\s*//#', $line) === 1) {
            $found[] = mb_trim($line);
        }
    }

    return $found;
}
