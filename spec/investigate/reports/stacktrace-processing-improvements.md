# Investigation Report: Stacktrace Processing Improvements

## Problem Statement

Stack traces captured by Sorane from monitored applications contain excessive vendor/framework code, making it difficult for users to identify the root cause of errors in their own application code.

**Example of the current problem:**
A typical stack trace shows 30+ frames, with most coming from vendor packages like `filament/infolists`, `filament/forms`, `livewire/livewire`, and `laravel/framework`. The user's actual application code is buried or entirely absent from the visible portion of the trace.

## Current Implementation

In `src/Sorane.php:99-106`, stack traces are captured using PHP's `getTraceAsString()` method:

```php
$trace = $exception->getTraceAsString();
$maxTraceLength = 5000;
$truncationSuffix = '... (truncated)';

if (mb_strlen($trace) > $maxTraceLength) {
    $trace = mb_substr($trace, 0, $maxTraceLength - mb_strlen($truncationSuffix)).$truncationSuffix;
}
```

**Issues with current approach:**
1. Uses `getTraceAsString()` which returns an unstructured text blob
2. No parsing or classification of frames
3. Simple character truncation may cut off important application frames
4. No distinction between vendor and application code

## Industry Best Practices

### 1. In-App Frame Classification (Sentry)

Sentry uses an `in_app` boolean property on each frame to distinguish between:
- **Application code** (`in_app: true`): Developer-written code handling requests/logic
- **Vendor code** (`in_app: false`): Framework infrastructure, libraries, middleware

Source: [Sentry Stack Trace Interface](https://develop.sentry.dev/sdk/event-payloads/stacktrace/)

### 2. Application Frames First (Flare/Ignition)

Laravel's Flare error tracking "only displays the application frames by default because those are the frames you're probably interested in" with an "Expand vendor frames" button for full details.

Source: [Flare Features](https://flareapp.io/features)

### 3. Frame Filtering (Whoops)

The Whoops library uses `FrameCollection` with injectable filters to process frames, allowing custom logic to categorize and filter frames based on path patterns.

Source: [Whoops Inspector](https://github.com/filp/whoops/blob/master/src/Whoops/Exception/Inspector.php)

### 4. Configurable Stack Trace Rules (Sentry)

Sentry allows users to configure rules like:
- `stack.abs_path:**/vendor/** -app` (mark vendor paths as non-app)
- `stack.abs_path:**/node_modules/** -app` (for JavaScript)

Source: [Sentry Stack Trace Rules](https://docs.sentry.io/concepts/data-management/event-grouping/stack-trace-rules/)

## Recommended Implementation

### Option A: Structured Frame Parsing (Recommended)

Replace `getTraceAsString()` with `getTrace()` to get parsed frame data:

```php
// Instead of:
$trace = $exception->getTraceAsString();

// Use:
$rawFrames = $exception->getTrace();
$frames = [];
$appPath = base_path();
$vendorPath = base_path('vendor');

foreach ($rawFrames as $index => $frame) {
    $file = $frame['file'] ?? null;
    $isApp = false;

    if ($file) {
        $isApp = str_starts_with($file, $appPath)
              && !str_starts_with($file, $vendorPath);
    }

    $frames[] = [
        'index' => $index,
        'file' => $file,
        'line' => $frame['line'] ?? null,
        'function' => $frame['function'] ?? null,
        'class' => $frame['class'] ?? null,
        'type' => $frame['type'] ?? null,
        'in_app' => $isApp,
    ];
}
```

**Benefits:**
- Enables client-side filtering/collapsing of vendor frames
- Preserves all frame data for those who need it
- Allows smart truncation (keep app frames, truncate vendor)
- Compatible with industry-standard error monitoring formats

### Option B: Server-Side Processing

If the Sorane backend already receives raw traces, implement the parsing logic server-side:

1. Parse the trace string into individual frames using regex
2. Classify each frame based on path patterns
3. Store both the raw trace and structured frames
4. Display application frames prominently in the UI

**Regex pattern for parsing PHP trace strings:**
```php
$pattern = '/^#(\d+)\s+(.+?)\((\d+)\):\s+(.+)$/m';
// Captures: frame number, file path, line number, function call
```

### Option C: Hybrid Approach

Send both formats:
- `trace`: Raw string for backwards compatibility
- `frames`: Structured array for enhanced display

```php
$data = [
    // ... existing fields ...
    'trace' => $traceString,  // Keep for backwards compat
    'frames' => $structuredFrames,  // New structured data
];
```

## Implementation Details

### Frame Classification Logic

```php
class FrameClassifier
{
    private array $appPaths;
    private array $vendorPaths;

    public function __construct()
    {
        $this->appPaths = [
            base_path('app'),
            base_path('routes'),
            base_path('database'),
            // User-configurable additional paths
            ...config('sorane.app_paths', []),
        ];

        $this->vendorPaths = [
            base_path('vendor'),
            // User-configurable vendor paths
            ...config('sorane.vendor_paths', []),
        ];
    }

    public function isAppFrame(?string $file): bool
    {
        if ($file === null) {
            return false;
        }

        // Check if it's explicitly a vendor path
        foreach ($this->vendorPaths as $vendorPath) {
            if (str_starts_with($file, $vendorPath)) {
                return false;
            }
        }

        // Check if it's in an app path
        foreach ($this->appPaths as $appPath) {
            if (str_starts_with($file, $appPath)) {
                return true;
            }
        }

        return false;
    }
}
```

### Smart Truncation Strategy

Instead of truncating by character count, prioritize frames:

```php
public function truncateFrames(array $frames, int $maxFrames = 50): array
{
    $appFrames = array_filter($frames, fn($f) => $f['in_app']);
    $vendorFrames = array_filter($frames, fn($f) => !$f['in_app']);

    // Always keep all app frames (up to a limit)
    $result = array_slice($appFrames, 0, min(count($appFrames), 30));

    // Add surrounding vendor context
    $remainingSlots = $maxFrames - count($result);

    if ($remainingSlots > 0) {
        // Keep vendor frames nearest to app frames
        $result = array_merge($result, array_slice($vendorFrames, 0, $remainingSlots));
    }

    return $result;
}
```

### Configuration Options

Add to `config/sorane.php`:

```php
'errors' => [
    // ... existing config ...

    // Paths considered "application code"
    'app_paths' => [
        // Default: app, routes, database
        // Add custom paths here
    ],

    // Paths considered "vendor/framework code"
    'vendor_paths' => [
        // Default: vendor
    ],

    // Maximum frames to send (prioritizes app frames)
    'max_frames' => 50,

    // Include arguments in frames (requires zend.exception_ignore_args=0)
    'include_frame_arguments' => false,
],
```

## UI/Display Recommendations

For the Sorane frontend/API consumers:

1. **Default View**: Show only `in_app: true` frames
2. **Expandable Sections**: Group consecutive vendor frames with a "Show X vendor frames" expander
3. **Visual Distinction**: Use different styling/colors for app vs vendor frames
4. **First App Frame Highlight**: Prominently display the first application frame as the likely error origin
5. **Vendor Frame Summary**: Show collapsed vendor sections like "12 frames in livewire/livewire"

## Migration Considerations

1. **Backwards Compatibility**: Keep sending `trace` string alongside new `frames` array
2. **Gradual Rollout**: Backend can start processing `frames` when available, fall back to parsing `trace`
3. **API Versioning**: Consider adding frame structure in a new API version

## Estimated Complexity

| Component | Effort |
|-----------|--------|
| Frame parsing in SDK | Low |
| Frame classification | Low |
| Configuration options | Low |
| Smart truncation | Medium |
| Backend API changes | Medium |
| Frontend UI changes | Medium-High |

## Conclusion

The recommended approach is **Option C (Hybrid)**, which:
- Maintains backwards compatibility
- Provides structured frame data for enhanced display
- Enables client-side filtering and sorting
- Follows industry standards (Sentry, Flare, Whoops)
- Allows incremental backend/frontend improvements

The key insight from industry leaders is that **classification of frames as "in-app" vs "vendor"** is the fundamental building block for all other improvements. Once frames are classified, collapsing, highlighting, filtering, and smart truncation all become straightforward.

## Sources

- [Sentry Stack Trace Interface](https://develop.sentry.dev/sdk/event-payloads/stacktrace/)
- [Sentry Stack Trace Rules](https://docs.sentry.io/concepts/data-management/event-grouping/stack-trace-rules/)
- [Flare Features](https://flareapp.io/features)
- [Flare Stack Trace Arguments](https://flareapp.io/docs/laravel/data-collection/stacktrace-arguments)
- [Whoops Inspector](https://github.com/filp/whoops/blob/master/src/Whoops/Exception/Inspector.php)
- [PHP Exception::getTrace](https://www.php.net/manual/en/exception.gettrace.php)
- [Laravel Error Handling Patterns](https://betterstack.com/community/guides/scaling-php/laravel-error-handling-patterns/)
