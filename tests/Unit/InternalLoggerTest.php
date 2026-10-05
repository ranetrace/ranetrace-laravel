<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Ranetrace\Laravel\Support\InternalLogger;

/*
 * When the internal channel itself fails, the line goes to PHP's error log
 * instead. Its context is diagnostics about a capture failure, which is often
 * exactly a value JSON cannot encode, so one such value must not cost the rest.
 */
test('the stderr fallback keeps the context when one value in it cannot be encoded', function (): void {
    $errorLog = tempnam(sys_get_temp_dir(), 'ranetrace-error-log-');
    $previous = ini_set('error_log', (string) $errorLog);

    Log::shouldReceive('channel')->with('ranetrace_internal')->andThrow(new RuntimeException('channel down'));

    try {
        InternalLogger::error('Something failed', ['type' => 'logs', 'ratio' => INF, 'name' => "Jos\xE9"]);

        $written = (string) file_get_contents((string) $errorLog);
    } finally {
        ini_set('error_log', $previous === false ? '' : $previous);
        @unlink((string) $errorLog);
    }

    expect($written)->toContain('[Ranetrace Internal ERROR] Something failed')
        ->toContain('"type":"logs"')
        ->toContain('Channel Error: channel down');
});
