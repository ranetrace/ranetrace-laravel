<?php

declare(strict_types=1);

namespace Ranetrace\Laravel\Services;

/**
 * The batch buffer, counting what one process adds to it.
 *
 * A test command binds one for the length of its run, so it can say how many
 * of its own items reached the buffer. The buffer's size before and after
 * would not say that: a drain or a capture in another process moves it at the
 * same moment, and a full buffer stays the same size when an item is added.
 */
class CountingBatchBuffer extends RanetraceBatchBuffer
{
    /**
     * @var array<string, int>
     */
    private array $added = [];

    public function addItems(string $type, array $dataItems): bool
    {
        $wasAdded = parent::addItems($type, $dataItems);

        if ($wasAdded) {
            $this->added[$type] = ($this->added[$type] ?? 0) + count($dataItems);
        }

        return $wasAdded;
    }

    /**
     * How many items of a type this instance added.
     */
    public function added(string $type): int
    {
        return $this->added[$type] ?? 0;
    }
}
