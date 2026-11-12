<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Jobs;

use Ranetrace\Laravel\Services\RanetraceBatchBuffer;

class HandleErrorJob extends BaseRanetraceJob
{
    public function __construct(
        protected array $errorData
    ) {
        $this->assignQueue();
    }

    public function handle(RanetraceBatchBuffer $buffer): void
    {
        // Add to buffer - batch jobs are dispatched by scheduler/command only
        $this->bufferOrRelease($buffer, 'errors', $this->bufferedItem());
    }

    /**
     * The item this job buffers: the captured data held to the allowed keys
     * and the per-item byte budget, or null when it is over that budget.
     *
     * @internal Shared with `ranetrace:test`, which sends this item straight to the API instead.
     *
     * @return array<string, mixed>|null
     */
    public function bufferedItem(): ?array
    {
        return $this->filterPayload($this->errorData);
    }

    protected function getConfigPath(): string
    {
        return 'ranetrace.errors';
    }

    /**
     * @return array<int, string>
     */
    protected function getAllowedKeys(): array
    {
        return [
            'message',
            'file',
            'line',
            'type',
            'environment',
            'trace',
            'headers',
            'context',
            'highlight_line',
            'user',
            'timestamp',
            'url',
            'method',
            'php_version',
            'framework',
            'framework_version',
            'is_console',
            'console_command',
            'console_arguments',
            'exception_context',
            'frames',
            'frames_omitted',
            'previous',
        ];
    }

    protected function shedsErrorFrames(): bool
    {
        return true;
    }
}
