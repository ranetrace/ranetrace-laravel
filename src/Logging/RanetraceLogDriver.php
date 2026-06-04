<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Logging;

use Monolog\Logger;
use Psr\Log\LoggerInterface;

class RanetraceLogDriver
{
    /**
     * Create a custom Monolog instance for Ranetrace
     */
    public function __invoke(array $config): LoggerInterface
    {
        $logger = new Logger($config['channel'] ?? 'ranetrace');

        // Fall back to the configured package level rather than 'debug' so the
        // in-code default matches config/ranetrace.php.
        $logger->pushHandler(new RanetraceLogHandler(
            $config['level'] ?? config('ranetrace.logging.level', 'notice'),
            $config['bubble'] ?? true
        ));

        return $logger;
    }
}
