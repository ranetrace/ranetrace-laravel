# Stacktrace Processing Implementation Plan

**Purpose:** Final actionable plan for improving stacktrace processing in the Sorane Laravel package.

**Date:** 2026-01-20

**Status:** Ready for implementation

---

## Executive Summary

This plan replaces the current string-based stack trace (`getTraceAsString()`) with a structured frames array. Each frame is classified as application or vendor code (`in_app` boolean), enabling smart truncation that prioritizes user code and provides rich metadata for error analysis.

Key decisions:
- **No backward compatibility** - clean break from the old `trace` string field
- **Structured frames** with `in_app` classification
- **Smart truncation** preserving all app frames (up to 30), filling to 50 total with adjacent vendor frames
- **Relative file paths** for cleaner output and security
- **Metadata fields** indicating truncation for UI display

---

## Data Structure Changes

### Replace `trace` with `frames`

**Current (to be removed):**
```json
{
  "trace": "#0 /var/www/app/Http/Controllers/UserController.php(42): App\\Services\\UserService->find()\n#1 ..."
}
```

**New structure:**
```json
{
  "frames": [
    {
      "index": 0,
      "file": "app/Http/Controllers/UserController.php",
      "line": 42,
      "function": "find",
      "class": "App\\Services\\UserService",
      "type": "->",
      "in_app": true
    },
    {
      "index": 1,
      "file": "vendor/laravel/framework/src/Illuminate/Routing/Controller.php",
      "line": 54,
      "function": "callAction",
      "class": "Illuminate\\Routing\\Controller",
      "type": "->",
      "in_app": false
    }
  ],
  "frames_meta": {
    "total_frames": 47,
    "omitted_frames": 0,
    "omitted_app_frames": 0,
    "has_app_frames": true
  }
}
```

### Frame Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `index` | integer | Yes | Original position in stack (0 = nearest to error) |
| `file` | string\|null | Yes | Relative file path (base_path stripped), null for internal functions |
| `line` | integer\|null | Yes | Line number, null if not available |
| `function` | string\|null | Yes | Function/method name |
| `class` | string\|null | Yes | Fully qualified class name |
| `type` | string\|null | Yes | Call type: `->` (instance), `::` (static), or null |
| `in_app` | boolean | Yes | True if application code, false if vendor |

### Metadata Fields

| Field | Type | Description |
|-------|------|-------------|
| `frames_meta.total_frames` | integer | Original frame count before truncation |
| `frames_meta.omitted_frames` | integer | Number of frames removed by truncation |
| `frames_meta.omitted_app_frames` | integer | Number of app frames removed (should be 0 in most cases) |
| `frames_meta.has_app_frames` | boolean | False if entire stack is vendor code |

---

## Frame Classification Logic

### Application Code Paths (in_app: true)

Files within these directories are classified as application code:
- `app/`
- `routes/`
- `database/`
- `config/`
- `resources/`
- `tests/`

### Vendor Code Paths (in_app: false)

Files within these directories are classified as vendor code:
- `vendor/`

### Classification Algorithm

```php
public function isAppFrame(?string $absolutePath): bool
{
    if ($absolutePath === null) {
        return false;
    }

    $basePath = base_path();
    $vendorPath = base_path('vendor');

    // Must be within project
    if (!str_starts_with($absolutePath, $basePath)) {
        return false;
    }

    // Vendor is always non-app
    if (str_starts_with($absolutePath, $vendorPath)) {
        return false;
    }

    // Everything else in project is app code
    return true;
}
```

---

## Smart Truncation Strategy

### Limits

| Limit | Value | Rationale |
|-------|-------|-----------|
| Max app frames | 30 | Captures deep call chains in user code |
| Max total frames | 50 | Balances payload size with context |
| Server max frames | 100 | Allows client flexibility for future changes |

### Algorithm

1. **Separate frames** into app frames and vendor frames
2. **Keep all app frames** up to 30, prioritizing frames nearest to the error (lowest index)
3. **Fill remaining slots** (up to 50 total) with vendor frames that are **adjacent to app frames**
4. **Preserve order** - maintain original PHP stack order (index 0 = nearest to error)
5. **Record metadata** - track total, omitted, and omitted_app counts

### Adjacent Vendor Frame Selection

When selecting which vendor frames to keep:

1. For each app frame, include vendor frames immediately before and after it in the original stack
2. This preserves the call context around user code
3. If still under 50 total, add more vendor frames starting from the beginning of the stack

Example:
```
Original stack (10 frames):
0: vendor/A  (adjacent to app frame 1)
1: app/X     ← app frame
2: vendor/B  (adjacent to app frame 1 and 3)
3: app/Y     ← app frame
4: vendor/C  (adjacent to app frame 3)
5: vendor/D
6: vendor/E
7: vendor/F
8: vendor/G
9: vendor/H

Result (assuming max 5 total, max 2 app):
- Keep frames 1, 3 (app frames)
- Keep frames 0, 2, 4 (adjacent vendor frames)
- Total: 5 frames
- Omitted: 5 (frames 5-9)
```

---

## File Path Handling

### Relative Path Conversion

Strip `base_path()` prefix from all file paths:

```php
public function toRelativePath(?string $absolutePath): ?string
{
    if ($absolutePath === null) {
        return null;
    }

    $basePath = base_path();

    if (str_starts_with($absolutePath, $basePath)) {
        return ltrim(substr($absolutePath, strlen($basePath)), '/');
    }

    // Files outside project keep absolute path
    return $absolutePath;
}
```

**Before:** `/var/www/html/app/Http/Controllers/UserController.php`
**After:** `app/Http/Controllers/UserController.php`

### Frames Without Files

Include frames with `file: null` to preserve the complete call chain:

```json
{
  "index": 5,
  "file": null,
  "line": null,
  "function": "array_map",
  "class": null,
  "type": null,
  "in_app": false
}
```

---

## Validation Constraints

### Client-Side (Package)

| Constraint | Value |
|------------|-------|
| Max frames sent | 50 |
| Max app frames | 30 |
| Max file path length | 500 characters |
| Max function name length | 200 characters |
| Max class name length | 300 characters |

### Server-Side (API)

| Constraint | Value |
|------------|-------|
| Max frames accepted | 100 |
| Max file path length | 500 characters |
| Max function name length | 200 characters |
| Max class name length | 300 characters |

Fields exceeding limits are truncated on the client side (not rejected).

---

## API Changes

### Endpoint

No endpoint change - remains `POST /v1/errors/store`

### Schema Changes

**Removed fields:**
- `trace` (string) - replaced by `frames`

**Added fields:**
- `frames` (array) - structured frame array
- `frames_meta` (object) - truncation metadata

### Validation Rules

```php
'frames' => ['required', 'array', 'max:100'],
'frames.*.index' => ['required', 'integer', 'min:0'],
'frames.*.file' => ['nullable', 'string', 'max:500'],
'frames.*.line' => ['nullable', 'integer', 'min:1'],
'frames.*.function' => ['nullable', 'string', 'max:200'],
'frames.*.class' => ['nullable', 'string', 'max:300'],
'frames.*.type' => ['nullable', 'string', 'in:->,::'],
'frames.*.in_app' => ['required', 'boolean'],
'frames_meta' => ['required', 'array'],
'frames_meta.total_frames' => ['required', 'integer', 'min:0'],
'frames_meta.omitted_frames' => ['required', 'integer', 'min:0'],
'frames_meta.omitted_app_frames' => ['required', 'integer', 'min:0'],
'frames_meta.has_app_frames' => ['required', 'boolean'],
```

---

## Database Storage

### Storage Format

Store `frames` as a JSON column:

```php
$table->json('frames');
$table->json('frames_meta');
```

### Migration Notes

- Add new `frames` and `frames_meta` columns
- Remove old `trace` column (after confirming no rollback needed)
- No data migration required (no backward compatibility)

---

## Error Grouping / Fingerprinting

### Strategy

Generate fingerprints using **app frames only**:

```php
public function generateFingerprint(array $frames): string
{
    $appFrames = array_filter($frames, fn($f) => $f['in_app']);

    $significant = array_map(fn($f) => [
        'file' => $f['file'],
        'line' => $f['line'],
        'function' => $f['function'],
    ], array_slice($appFrames, 0, 5));

    return hash('sha256', json_encode($significant));
}
```

**Rationale:** Vendor/framework updates shouldn't create new error groups for the same underlying issue in user code.

---

## Existing Field Preservation

### Keep Separate Top-Level Fields

The following fields remain at the error level (not removed in favor of frames):

| Field | Source | Rationale |
|-------|--------|-----------|
| `file` | `$exception->getFile()` | Direct access to error location without parsing frames |
| `line` | `$exception->getLine()` | Direct access to error line |
| `context` | Code snippet (11 lines) | Code context for the error location only |
| `highlight_line` | Relative line in context | Works with context field |

---

## Code Architecture

### New Service Class

Create `src/Services/ErrorCapture.php`:

```php
namespace Sorane\Services;

class ErrorCapture
{
    public function capture(Throwable $exception): array
    {
        return [
            // ... existing fields ...
            'frames' => $this->processFrames($exception->getTrace()),
            'frames_meta' => $this->getFramesMeta(),
        ];
    }

    public function processFrames(array $rawFrames): array
    {
        // 1. Parse and classify frames
        // 2. Apply smart truncation
        // 3. Convert to relative paths
        // 4. Return structured array
    }

    public function isAppFrame(?string $path): bool { /* ... */ }

    public function toRelativePath(?string $path): ?string { /* ... */ }

    public function truncateFrames(array $frames): array { /* ... */ }
}
```

### Integration

Update `Sorane.php` to use the new service:

```php
use Sorane\Services\ErrorCapture;

public function captureException(Throwable $exception): void
{
    $capture = app(ErrorCapture::class);
    $data = $capture->capture($exception);

    $this->buffer->add('errors', $data);
}
```

---

## Spec Files to Update

### Files Requiring Updates

1. **`specs/sorane/datacapture/errors.md`**
   - Replace `trace` field spec with `frames` and `frames_meta`
   - Update field specifications table
   - Update truncation documentation
   - Add frame classification documentation

2. **`specs/sorane/datatransfer/api/api-specification.md`**
   - Update validation rules for errors endpoint
   - Document new frame structure in request body examples

---

## Implementation Checklist

### Package (Client) Changes

- [ ] Create `src/Services/ErrorCapture.php` service class
- [ ] Implement `processFrames()` with classification logic
- [ ] Implement `isAppFrame()` path classification
- [ ] Implement `toRelativePath()` path conversion
- [ ] Implement smart truncation algorithm
- [ ] Implement frames metadata generation
- [ ] Update `Sorane.php` to use ErrorCapture service
- [ ] Remove old `trace` field generation
- [ ] Write unit tests for frame processing
- [ ] Write integration tests for error capture

### Backend (API) Changes

- [ ] Update validation rules for errors endpoint
- [ ] Add database migration for `frames` and `frames_meta` columns
- [ ] Remove `trace` column from schema
- [ ] Update error fingerprinting to use app frames
- [ ] Update any error display logic

### Spec Updates

- [ ] Update `specs/sorane/datacapture/errors.md`
- [ ] Update `specs/sorane/datatransfer/api/api-specification.md`

---

## Payload Size Analysis

### Estimated Frame Size

Average frame JSON size: ~200 bytes

```json
{"index":0,"file":"app/Http/Controllers/UserController.php","line":42,"function":"show","class":"App\\Http\\Controllers\\UserController","type":"->","in_app":true}
```

### Payload Comparison

| Scenario | Old (trace string) | New (frames array) |
|----------|-------------------|-------------------|
| 10 frames | ~800 bytes | ~2,000 bytes |
| 30 frames | ~2,400 bytes | ~6,000 bytes |
| 50 frames | ~4,000 bytes | ~10,000 bytes |

**Impact:** Slightly larger payloads but well within the 5MB API limit. Benefits of structured data outweigh the size increase.

---

## Summary of Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Data format | Frames only (no trace) | No backward compat needed, cleaner API |
| Frame fields | Core only (no args) | Security, payload size |
| Frame limits | 30 app / 50 total | Balance context with size |
| App detection | Sensible defaults | Simplicity, no config needed |
| File paths | Relative | Security, cleaner output |
| No-file frames | Include with null | Preserve call chain |
| Server max | 100 frames | Future flexibility |
| DB storage | JSON column | Simple, queryable |
| Field limits | Conservative | Prevent abuse |
| No app frames | Flag + vendor frames | Maximum information |
| Frame order | PHP order (innermost first) | Standard, no transformation |
| Fingerprinting | App frames only | Stable grouping |
| Top-level fields | Keep file/line | Direct access |
| Code context | Top-level only | Payload size |
| App overflow | First 30 | Nearest to error |
| Vendor selection | Adjacent to app | Context preservation |
| Truncation meta | Include counts | UI can show omissions |
| Code location | ErrorCapture service | Clean architecture |
| Tests directory | App code | User-written code |
