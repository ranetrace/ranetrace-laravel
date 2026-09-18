<?php

declare(strict_types=1);

/*
 * Boost copies the guideline into the CLAUDE.md of every application that
 * installs the package, so an agent reads it on every turn of every session,
 * whether or not the session touches Ranetrace. A skill is read only when an
 * agent activates it. The guideline therefore carries what must apply without
 * anyone asking (the wiring that is not automatic, the credential that must
 * never land in .env) and points at a skill for the rest. In September 2026
 * the MCP section went from 4.9 KB to one paragraph on that reasoning.
 *
 * Two things can undo that quietly: detail growing back into the guideline one
 * sentence at a time, and a pointer that names a skill the package no longer
 * ships, which sends an agent to look for something that is not there.
 */

/**
 * The guideline's source, linked into the TIA graph with the rest of the Boost
 * resources so a cached pass never replays over an edited file.
 */
function boostGuideline(): string
{
    boostResourceFiles();

    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/boost/guidelines/core.blade.php');
}

test('every skill the guideline points at ships with the package', function (): void {
    preg_match_all('/activate the `([a-z0-9-]+)` skill/', boostGuideline(), $matches);

    $pointedAt = array_values(array_unique($matches[1]));

    expect($pointedAt)
        ->toContain('ranetrace-error-tracking')
        ->toContain('ranetrace-analytics');

    foreach ($pointedAt as $skill) {
        $path = dirname(__DIR__, 2).'/resources/boost/skills/'.$skill.'/SKILL.md';

        expect(is_file($path))->toBeTrue("The guideline tells an agent to activate `{$skill}`, and no such skill ships.");

        expect((string) file_get_contents($path))
            ->toMatch('/^name: '.preg_quote($skill, '/').'$/m', "The skill under skills/{$skill} does not carry that name, so an agent cannot activate it by the name the guideline gives.");
    }
});

test('the MCP pointer leads to a skill whose description says it covers MCP', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2).'/resources/boost/skills/ranetrace-error-tracking/SKILL.md');

    preg_match('/^description: (.+)$/m', $skill, $description);

    // The description is the only part of a skill an agent sees before it decides
    // to load it, so these are the words that make the pointer work.
    expect($description[1] ?? '')
        ->toContain('MCP')
        ->toContain('OAuth')
        ->toContain('search_tools')
        ->toContain('verdicts')
        ->toContain('notification rules');
});

test('the always-on guideline stays light', function (): void {
    $bytes = mb_strlen(boostGuideline(), '8bit');

    expect($bytes)->toBeLessThanOrEqual(
        7168,
        "The guideline is {$bytes} bytes and every consuming project pays for each of them on every turn. Keep what prevents a wrong action unasked, and move the detail into the skill that covers the feature.",
    );
});
