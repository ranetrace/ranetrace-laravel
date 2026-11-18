<?php

declare(strict_types=1);

/**
 * The exceptions `ranetrace:test-errors` reports, each behind a closure that
 * makes it. `ranetrace:test` sends the first, `simple`, whose message matches
 * the PHP SDK's test exception.
 *
 * They are made here, outside `src/`, because `Ranetrace::report()` drops every
 * exception whose file is under `src/` as one the package threw itself, so a
 * test exception made in the command would never be captured.
 *
 * The `deep` one is made three calls down, to give its stack trace some depth.
 */
$deepest = static fn (): Exception => new Exception('Test exception with deeper stack trace');
$middle = static fn (): Exception => $deepest();
$outer = static fn (): Exception => $middle();

return [
    'simple' => static fn (): Exception => new Exception('Test exception from Ranetrace'),
    'runtime' => static fn (): RuntimeException => new RuntimeException('Test runtime exception', 500),
    'deep' => static fn (): Exception => $outer(),
    'custom' => static fn (): Exception => new Exception('Database connection failed: Connection refused on localhost:5432'),
];
