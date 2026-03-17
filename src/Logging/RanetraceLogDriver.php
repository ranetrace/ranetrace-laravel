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

        // Add the Ranetrace handler
        $logger->pushHandler(new RanetraceLogHandler(
            $config['level'] ?? 'debug',
            $config['bubble'] ?? true
        ));

        return $logger;
    }
}
