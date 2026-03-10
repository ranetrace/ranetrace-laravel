# Resolve Errors - MCP Feature Brainstorm Report

## Overview

Add the ability to manage error states via MCP, enabling AI conversations to resolve, ignore, snooze, reopen, and soft-delete errors directly. Includes full state management with audit logging via spatie/laravel-activitylog.

## Decisions Made

### 1. Supported Operations

**Decision**: Full state management

| Operation | Description |
|-----------|-------------|
| Resolve | Mark error as resolved |
| Unresolve/Reopen | Move resolved error back to open state |
| Ignore | Permanently ignore error (suppress notifications) |
| Unignore | Remove ignore status, return to open |
| Snooze | Temporarily suppress error for a duration |
| Unsnooze | Remove snooze, return to normal state |
| Delete | Soft delete (archive) the error |

**Requirements**:
- Create action-based endpoints for each operation
- Support both PHP errors and JavaScript errors via `type` parameter
- Operations are idempotent (resolving already-resolved error succeeds silently)

### 2. Soft Delete

**Decision**: Add SoftDeletes to Error model

- Add `deleted_at` column to errors table
- Add `SoftDeletes` trait to Error model
- Also add to JavaScriptError model for consistency

**Requirements**:
- Create migration adding `deleted_at` to `errors` and `javascript_errors` tables
- Update Error and JavaScriptError models with SoftDeletes trait
- Ensure existing queries filter out soft-deleted records

### 3. Audit Logging

**Decision**: Use spatie/laravel-activitylog package

- Install `spatie/laravel-activitylog` via composer
- Log all state changes (not just MCP, but UI and system too)
- Capture basic info: action, user/causer, timestamp

**Requirements**:
- Add `spatie/laravel-activitylog` to composer.json
- Run package migrations
- Configure activity logging on Error and JavaScriptError models
- Log state changes with action description

### 4. API Endpoint Structure

**Decision**: Action-based endpoints

| Action | Endpoint | Method |
|--------|----------|--------|
| Resolve | `/mcp/v1/errors/{id}/resolve` | POST |
| Reopen | `/mcp/v1/errors/{id}/reopen` | POST |
| Ignore | `/mcp/v1/errors/{id}/ignore` | POST |
| Unignore | `/mcp/v1/errors/{id}/unignore` | POST |
| Snooze | `/mcp/v1/errors/{id}/snooze` | POST |
| Unsnooze | `/mcp/v1/errors/{id}/unsnooze` | POST |
| Delete | `/mcp/v1/errors/{id}` | DELETE |
| Activity | `/mcp/v1/errors/{id}/activity` | GET |

**Requirements**:
- Create `ErrorActionsController` for state management
- Support `type` query parameter (`php` or `javascript`, default `php`)
- Return summary with new state and activity log entry ID

### 5. Bulk Operations

**Decision**: Support bulk with 50 error limit

- Allow changing state of multiple errors in one request
- Maximum 50 errors per request
- All-or-nothing transaction (rollback if any fail)

**Bulk Endpoints**:

| Action | Endpoint | Method |
|--------|----------|--------|
| Bulk Resolve | `/mcp/v1/errors/bulk/resolve` | POST |
| Bulk Reopen | `/mcp/v1/errors/bulk/reopen` | POST |
| Bulk Ignore | `/mcp/v1/errors/bulk/ignore` | POST |
| Bulk Delete | `/mcp/v1/errors/bulk/delete` | POST |

**Requirements**:
- Accept `error_ids` array in request body
- Wrap operations in database transaction
- Validate all error IDs belong to authenticated project before proceeding

### 6. Snooze Duration

**Decision**: Support both presets and custom datetime

**Preset Durations**:
- `1h` - 1 hour
- `8h` - 8 hours (workday)
- `24h` - 24 hours
- `7d` - 7 days
- `30d` - 30 days

**Custom**: Accept ISO 8601 datetime via `until` parameter

**No "forever" snooze** - guide users to use "ignore" for permanent suppression

**Requirements**:
- Accept `duration` parameter with preset values
- Accept `until` parameter with ISO 8601 datetime
- Validate `until` is in the future
- If both provided, `until` takes precedence

### 7. JavaScript Error Support

**Decision**: Same endpoints with type parameter

- Use same endpoints for both PHP and JavaScript errors
- Add optional `type` query parameter (default: `php`)
- Values: `php`, `javascript` (or `js` as alias)

**Requirements**:
- Check `type` parameter and query appropriate model
- Both error types have same state fields (is_resolved, is_ignored, snooze_until)

### 8. Response Format

**Decision**: Summary with state

```json
{
  "error": {
    "id": "err_123",
    "state": "resolved",
    "is_resolved": true,
    "is_ignored": false,
    "snooze_until": null
  },
  "activity": {
    "id": 456,
    "action": "resolved",
    "performed_at": "2026-01-23T12:34:56+00:00"
  }
}
```

### 9. Activity Log API

**Decision**: Expose via list endpoint

Add `GET /mcp/v1/errors/{id}/activity` to view state change history.

**Response**:
```json
{
  "activities": [
    {
      "id": 456,
      "action": "resolved",
      "causer_name": "AI Agent",
      "performed_at": "2026-01-23T12:34:56+00:00"
    },
    {
      "id": 455,
      "action": "snoozed",
      "causer_name": "John Doe",
      "performed_at": "2026-01-22T10:00:00+00:00"
    }
  ],
  "meta": {
    "total": 5,
    "limit": 50
  }
}
```

**Requirements**:
- List activities for the error in descending order (newest first)
- Include causer name (user who performed action)
- Support pagination with `limit` and `offset`

---

## API Endpoint Specifications

### POST /mcp/v1/errors/{id}/resolve

Mark an error as resolved.

**Query Parameters**:
- `type` (string, optional): `php` (default) or `javascript`

**Response** (200 OK):
```json
{
  "error": {
    "id": "err_123",
    "state": "resolved",
    "is_resolved": true,
    "is_ignored": false,
    "snooze_until": null
  },
  "activity": {
    "id": 456,
    "action": "resolved",
    "performed_at": "2026-01-23T12:34:56+00:00"
  }
}
```

### POST /mcp/v1/errors/{id}/reopen

Move error back to open state.

**Response** (200 OK):
```json
{
  "error": {
    "id": "err_123",
    "state": "open",
    "is_resolved": false,
    "is_ignored": false,
    "snooze_until": null
  },
  "activity": {
    "id": 457,
    "action": "reopened",
    "performed_at": "2026-01-23T12:35:00+00:00"
  }
}
```

### POST /mcp/v1/errors/{id}/ignore

Permanently ignore the error.

**Response** (200 OK):
```json
{
  "error": {
    "id": "err_123",
    "state": "ignored",
    "is_resolved": false,
    "is_ignored": true,
    "snooze_until": null
  },
  "activity": {
    "id": 458,
    "action": "ignored",
    "performed_at": "2026-01-23T12:36:00+00:00"
  }
}
```

### POST /mcp/v1/errors/{id}/snooze

Temporarily snooze the error.

**Request Body**:
```json
{
  "duration": "24h"
}
```

Or with custom datetime:
```json
{
  "until": "2026-01-25T09:00:00+00:00"
}
```

**Response** (200 OK):
```json
{
  "error": {
    "id": "err_123",
    "state": "snoozed",
    "is_resolved": false,
    "is_ignored": false,
    "snooze_until": "2026-01-24T12:36:00+00:00"
  },
  "activity": {
    "id": 459,
    "action": "snoozed",
    "performed_at": "2026-01-23T12:36:00+00:00"
  }
}
```

### POST /mcp/v1/errors/{id}/unsnooze

Remove snooze from error.

**Response** (200 OK):
```json
{
  "error": {
    "id": "err_123",
    "state": "open",
    "is_resolved": false,
    "is_ignored": false,
    "snooze_until": null
  },
  "activity": {
    "id": 460,
    "action": "unsnoozed",
    "performed_at": "2026-01-23T12:37:00+00:00"
  }
}
```

### DELETE /mcp/v1/errors/{id}

Soft delete (archive) the error.

**Query Parameters**:
- `type` (string, optional): `php` (default) or `javascript`

**Response** (200 OK):
```json
{
  "message": "Error archived successfully",
  "activity": {
    "id": 461,
    "action": "archived",
    "performed_at": "2026-01-23T12:38:00+00:00"
  }
}
```

### POST /mcp/v1/errors/bulk/resolve

Resolve multiple errors at once.

**Request Body**:
```json
{
  "error_ids": ["err_123", "err_124", "err_125"],
  "type": "php"
}
```

**Response** (200 OK):
```json
{
  "resolved_count": 3,
  "errors": [
    {"id": "err_123", "state": "resolved"},
    {"id": "err_124", "state": "resolved"},
    {"id": "err_125", "state": "resolved"}
  ]
}
```

### GET /mcp/v1/errors/{id}/activity

Get activity log for an error.

**Query Parameters**:
- `type` (string, optional): `php` (default) or `javascript`
- `limit` (int, optional): Max entries to return (default 50)
- `offset` (int, optional): Number of entries to skip

**Response** (200 OK):
```json
{
  "activities": [
    {
      "id": 456,
      "action": "resolved",
      "causer_name": "AI Agent",
      "performed_at": "2026-01-23T12:34:56+00:00"
    }
  ],
  "meta": {
    "total": 10,
    "limit": 50,
    "offset": 0
  }
}
```

---

## Database Changes Required

### Migration: Add soft deletes to errors table

```php
Schema::table('errors', function (Blueprint $table) {
    $table->softDeletes();
});
```

### Migration: Add soft deletes to javascript_errors table

```php
Schema::table('javascript_errors', function (Blueprint $table) {
    $table->softDeletes();
});
```

### Package: Install spatie/laravel-activitylog

```bash
composer require spatie/laravel-activitylog
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

---

## MCP Tool Specifications (for sorane-laravel package)

### resolve-error

Mark an error as resolved.

**Parameters**:
- `error_id` (required): The error ID (with or without `err_` prefix)
- `type` (optional): `php` (default) or `javascript`

### reopen-error

Move a resolved error back to open state.

**Parameters**:
- `error_id` (required): The error ID
- `type` (optional): `php` or `javascript`

### ignore-error

Permanently ignore an error.

**Parameters**:
- `error_id` (required): The error ID
- `type` (optional): `php` or `javascript`

### unignore-error

Remove ignore status from an error.

**Parameters**:
- `error_id` (required): The error ID
- `type` (optional): `php` or `javascript`

### snooze-error

Temporarily snooze an error.

**Parameters**:
- `error_id` (required): The error ID
- `duration` (optional): Preset duration (`1h`, `8h`, `24h`, `7d`, `30d`)
- `until` (optional): ISO 8601 datetime to snooze until
- `type` (optional): `php` or `javascript`

Note: Either `duration` or `until` must be provided. If both are provided, `until` takes precedence.

### unsnooze-error

Remove snooze from an error.

**Parameters**:
- `error_id` (required): The error ID
- `type` (optional): `php` or `javascript`

### delete-error

Soft delete (archive) an error.

**Parameters**:
- `error_id` (required): The error ID
- `type` (optional): `php` or `javascript`

### get-error-activity

Get the activity log for an error.

**Parameters**:
- `error_id` (required): The error ID
- `type` (optional): `php` or `javascript`
- `limit` (optional): Max entries (default 50)

### Bulk Tools

### bulk-resolve-errors

Resolve multiple errors at once.

**Parameters**:
- `error_ids` (required): Array of error IDs (max 50)
- `type` (optional): `php` or `javascript`

### bulk-ignore-errors

Ignore multiple errors at once.

**Parameters**:
- `error_ids` (required): Array of error IDs (max 50)
- `type` (optional): `php` or `javascript`

### bulk-delete-errors

Soft delete multiple errors at once.

**Parameters**:
- `error_ids` (required): Array of error IDs (max 50)
- `type` (optional): `php` or `javascript`

---

## Files to Create/Modify

### New Files

1. `app/Http/Controllers/Api/V1/Mcp/ErrorActionsController.php`
2. `app/Http/Requests/V1/Mcp/SnoozeErrorRequest.php`
3. `app/Http/Requests/V1/Mcp/BulkErrorActionRequest.php`
4. `app/Http/Resources/V1/Mcp/ErrorStateResource.php`
5. `app/Http/Resources/V1/Mcp/ActivityResource.php`
6. `app/Http/Resources/V1/Mcp/ActivityCollection.php`
7. `database/migrations/xxxx_add_soft_deletes_to_errors_table.php`
8. `database/migrations/xxxx_add_soft_deletes_to_javascript_errors_table.php`
9. `tests/Feature/Api/V1/Mcp/ErrorActionsControllerTest.php`

### Modified Files

1. `app/Models/Error.php` - Add SoftDeletes trait, LogsActivity trait
2. `app/Models/JavaScriptError.php` - Add SoftDeletes trait, LogsActivity trait
3. `routes/api.php` - Add error action routes
4. `composer.json` - Add spatie/laravel-activitylog dependency

---

## Code Architecture

### Centralized Logic

All logic that is shared between MCP API requests and users via the UI must use centralized, shared code. No duplication is allowed.

**Requirements**:
- Extract shared business logic into Service classes or Actions
- Controllers (both API and web) should delegate to these shared services
- State change logic (resolve, ignore, snooze, etc.) should live in a shared `ErrorStateService` or Action classes
- Activity logging should be triggered from the shared service layer, not individual controllers
- Example: `ResolveErrorAction` class that both MCP `ErrorActionsController` and UI controllers call

---

## Test Requirements

### Feature Tests

**Single Operations:**
- Resolve error - 200 with updated state
- Resolve already-resolved error - 200 (idempotent)
- Resolve error from different project - 403
- Resolve non-existent error - 404
- Reopen resolved error - 200
- Ignore error - 200
- Unignore error - 200
- Snooze with preset duration - 200
- Snooze with custom datetime - 200
- Snooze with past datetime - 422 validation error
- Snooze without duration or until - 422
- Unsnooze error - 200
- Delete (soft delete) error - 200
- Get activity log - 200 with activities

**JavaScript Errors:**
- All operations work with type=javascript parameter
- Correct model is queried based on type

**Bulk Operations:**
- Bulk resolve multiple errors - 200
- Bulk resolve with invalid error ID - 422 (all or nothing)
- Bulk resolve exceeding 50 limit - 422
- Bulk resolve errors from different project - 403

**Activity Logging:**
- Activity entry created for each state change
- Activity includes correct causer (AI Agent user)
- Activity log pagination works correctly
