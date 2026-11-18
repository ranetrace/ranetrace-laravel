<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Ranetrace\Laravel\Jobs\HandleErrorJob;
use Ranetrace\Laravel\Jobs\HandleEventJob;
use Ranetrace\Laravel\Jobs\HandleJavaScriptErrorJob;
use Ranetrace\Laravel\Jobs\HandleLogJob;
use Ranetrace\Laravel\Services\RanetraceBatchBuffer;
use Ranetrace\Laravel\Tests\Contract\ContractFields;
use Ranetrace\Php\Contract\WireContract;

/**
 * The capture jobs' allow-lists are the last gate before an item is buffered:
 * `filterPayload()` keeps only the keys `getAllowedKeys()` names, so a key the
 * endpoint does not declare cannot reach the wire, and a key the endpoint
 * requires cannot survive a typo here. The errors endpoint allow-lists its
 * field set server-side, where one unexpected key refuses the item, and every
 * error item carries the same keys, so a drift between these lists and
 * `contract/items/*.json` loses every error rather than being a style issue.
 *
 * The fixtures come from the sibling core package (`ranetrace/ranetrace-php`),
 * which both SDKs and the backend read, so this suite compares against one
 * description of the wire rather than a second transcription of it.
 */
/**
 * The buffer type string a capture job writes under, discovered by running it
 * rather than transcribed from the class.
 *
 * The type is what ties a job to a contract item type, and it is a bare string
 * argument inside `handle()`, so reading it back out of the buffer is the only
 * way to learn it that a rename cannot silently invalidate.
 */
function contractCaptureJobBufferType(string $jobClass): string
{
    Cache::store('array')->flush();

    $buffer = new RanetraceBatchBuffer;

    (new $jobClass([]))->handle($buffer);

    foreach (RanetraceBatchBuffer::TYPES as $type) {
        if ($buffer->getItems($type, 1) !== []) {
            return $type;
        }
    }

    throw new RuntimeException("{$jobClass} buffered nothing, so its type could not be discovered.");
}

/**
 * @return array<int, string>
 */
function contractCaptureJobAllowedKeys(string $jobClass): array
{
    return (new ReflectionMethod($jobClass, 'getAllowedKeys'))->invoke(new $jobClass([]));
}

/**
 * @return array<int, class-string>
 */
function contractCaptureJobs(): array
{
    return [HandleErrorJob::class, HandleEventJob::class, HandleLogJob::class, HandleJavaScriptErrorJob::class];
}

test('the capture jobs cover exactly the item types the contract names', function (): void {
    $types = array_map(contractCaptureJobBufferType(...), contractCaptureJobs());

    expect($types)->toEqualCanonicalizing(WireContract::itemTypes());
});

test('a capture job allow-lists exactly the fields its endpoint declares', function (string $jobClass): void {
    $type = contractCaptureJobBufferType($jobClass);

    expect(contractCaptureJobAllowedKeys($jobClass))
        ->toEqualCanonicalizing(ContractFields::topLevelKeys(WireContract::item($type)['fields']));
})->with(contractCaptureJobs());
