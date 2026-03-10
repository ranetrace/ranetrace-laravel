# Search Errors - MCP Feature Brainstorm Report

## Overview

Add advanced search capabilities to find errors matching specific criteria via MCP. Enhances the existing list endpoint with comprehensive filtering, sorting, and cursor-based pagination. Supports searching across both PHP and JavaScript errors with configurable type filtering.

## Decisions Made

### 1. Search Scope

**Decision**: Basic filters (no full-text search)

Supported filter types:
- Error type (PHP, JavaScript, or both)
- Environment (include/exclude lists)
- Status (open, resolved, ignored, snoozed, plus composite filters)
- Date range (first/last occurred with presets and custom ranges)
- Occurrence count (min/max and presets)

**Requirements**:
- Enhance existing GET /errors endpoint with additional query parameters
- Create new `search-errors` MCP tool
- Support combining multiple filters with AND logic

### 2. Cross-Type Search

**Decision**: Configurable

- Default: Search both PHP and JavaScript errors
- Allow filtering to single type via `type` parameter
- Results include type indicator for each error

**Requirements**:
- Query both Error and JavaScriptError models
- Merge and sort results
- Include `type` field in each result (`php` or `javascript`)

### 3. Status Filters

**Decision**: Composite filters

| Filter Value | Matches |
|-------------|---------|
| `open` | Errors that are not resolved and not ignored |
| `resolved` | Errors with `is_resolved = true` |
| `ignored` | Errors with `is_ignored = true` |
| `snoozed` | Errors with `snooze_until` in the future |
| `active` | Open + Snoozed (errors needing attention eventually) |
| `closed` | Resolved + Ignored (errors considered "done") |

**Requirements**:
- Accept single status or array of statuses
- Implement composite filter logic

### 4. Date Range Filtering

**Decision**: Both dimensions (first/last occurred) with presets and custom

**Preset Durations**:
- `1h` - Last hour
- `6h` - Last 6 hours
- `24h` - Last 24 hours
- `7d` - Last 7 days
- `30d` - Last 30 days
- `90d` - Last 90 days

**Custom Range**: ISO 8601 datetime for `from` and `to`

**Parameters**:
- `first_occurred_period` - Preset for when error first appeared
- `first_occurred_from` / `first_occurred_to` - Custom range
- `last_occurred_period` - Preset for when error last occurred
- `last_occurred_from` / `last_occurred_to` - Custom range

**Requirements**:
- Support both presets and custom ranges
- Custom range takes precedence if both provided
- Allow filtering on either or both dimensions

### 5. Occurrence Count Filtering

**Decision**: Min/max and presets

**Preset Thresholds**:

| Preset | Criteria |
|--------|----------|
| `critical` | > 100 occurrences |
| `frequent` | > 20 occurrences |
| `moderate` | > 5 occurrences |
| `rare` | < 5 occurrences |

**Custom Parameters**:
- `min_occurrences` - Minimum occurrence count
- `max_occurrences` - Maximum occurrence count
- `occurrence_level` - Preset name

**Requirements**:
- Presets and min/max can be combined (AND logic)
- Validate min <= max if both provided

### 6. Environment Filtering

**Decision**: Include/exclude lists

**Parameters**:
- `environments` - Array of environments to include
- `exclude_environments` - Array of environments to exclude

**Requirements**:
- Cannot use both include and exclude in same request (422 error)
- Environment names are case-insensitive

### 7. Sorting

**Decision**: Basic sorts

| Sort Field | Description |
|------------|-------------|
| `last_occurred` | When error last occurred (default, descending) |
| `first_occurred` | When error first appeared |
| `occurrence_count` | Number of occurrences |

**Parameters**:
- `sort` - Field to sort by
- `direction` - `asc` or `desc` (default varies by field)

**Requirements**:
- Default sort: `last_occurred` descending
- Consistent sorting across PHP and JS errors

### 8. Pagination

**Decision**: Cursor pagination

- Default page size: 25 errors
- Maximum page size: 100 errors
- Use cursor-based pagination for consistent results

**Parameters**:
- `limit` - Number of errors per page (default 25, max 100)
- `cursor` - Opaque cursor for next/previous page

**Response includes**:
- `next_cursor` - Cursor for next page (null if no more results)
- `prev_cursor` - Cursor for previous page (null if on first page)

**Requirements**:
- Generate opaque cursor encoding sort field and last seen value
- Cursors must be stable even if new errors are added

### 9. Response Aggregations

**Decision**: Basic counts

Include alongside results:
- `total_count` - Total matching errors (before pagination)
- `count_by_status` - Count of open, resolved, ignored, snoozed

**Requirements**:
- Counts calculated across the full result set, not just current page
- Counts consider all applied filters

### 10. Archived Errors

**Decision**: Excluded by default

- Soft-deleted errors excluded from search results by default
- Add `include_archived=true` parameter to include them
- Add `restore-error` MCP tool to undelete archived errors

**Requirements**:
- Default queries use `whereNull('deleted_at')`
- Include archived parameter adds `withTrashed()` scope

### 11. MCP Tool Design

**Decision**: Dedicated search-errors tool with full parameter support

Create new `search-errors` tool separate from existing `latest-errors` tool:
- `latest-errors` - Quick access to recent errors (simple)
- `search-errors` - Advanced filtering and search (powerful)

---

## API Endpoint Specification

### GET /mcp/v1/errors

Enhanced list endpoint with search capabilities.

**Query Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | `php`, `javascript`, `all` (default: `all`) |
| `status` | string/array | Status filter(s): `open`, `resolved`, `ignored`, `snoozed`, `active`, `closed` |
| `environments` | array | Include only these environments |
| `exclude_environments` | array | Exclude these environments |
| `first_occurred_period` | string | Preset: `1h`, `6h`, `24h`, `7d`, `30d`, `90d` |
| `first_occurred_from` | string | ISO 8601 datetime |
| `first_occurred_to` | string | ISO 8601 datetime |
| `last_occurred_period` | string | Preset: `1h`, `6h`, `24h`, `7d`, `30d`, `90d` |
| `last_occurred_from` | string | ISO 8601 datetime |
| `last_occurred_to` | string | ISO 8601 datetime |
| `occurrence_level` | string | `critical`, `frequent`, `moderate`, `rare` |
| `min_occurrences` | int | Minimum occurrence count |
| `max_occurrences` | int | Maximum occurrence count |
| `sort` | string | `last_occurred`, `first_occurred`, `occurrence_count` |
| `direction` | string | `asc`, `desc` |
| `limit` | int | Results per page (default 25, max 100) |
| `cursor` | string | Pagination cursor |
| `include_archived` | bool | Include soft-deleted errors (default false) |

**Response** (200 OK):
```json
{
  "errors": [
    {
      "id": "err_123",
      "type": "php",
      "message": "Undefined variable $foo",
      "error_type": "ErrorException",
      "environment": "production",
      "occurrence_count": 42,
      "first_occurred_at": "2026-01-20T10:00:00+00:00",
      "last_occurred_at": "2026-01-23T12:34:56+00:00",
      "status": "open",
      "is_resolved": false,
      "is_ignored": false,
      "snooze_until": null,
      "archived": false
    }
  ],
  "meta": {
    "total_count": 150,
    "count_by_status": {
      "open": 100,
      "resolved": 30,
      "ignored": 15,
      "snoozed": 5
    },
    "limit": 25,
    "next_cursor": "eyJsYXN0X29jY3VycmVkIjoiMjAyNi0wMS...",
    "prev_cursor": null
  }
}
```

### POST /mcp/v1/errors/{id}/restore

Restore a soft-deleted error.

**Query Parameters**:
- `type` (string, optional): `php` (default) or `javascript`

**Response** (200 OK):
```json
{
  "error": {
    "id": "err_123",
    "message": "Undefined variable $foo",
    "status": "open"
  },
  "activity": {
    "id": 500,
    "action": "restored",
    "performed_at": "2026-01-23T12:40:00+00:00"
  }
}
```

---

## MCP Tool Specifications (for sorane-laravel package)

### search-errors

Search for errors matching specific criteria.

**Parameters**:
- `type` (optional): `php`, `javascript`, or `all` (default: `all`)
- `status` (optional): Status filter - `open`, `resolved`, `ignored`, `snoozed`, `active`, `closed`, or array
- `environments` (optional): Array of environments to include
- `exclude_environments` (optional): Array of environments to exclude
- `first_occurred_period` (optional): Preset period for first occurrence
- `first_occurred_from` (optional): ISO 8601 datetime
- `first_occurred_to` (optional): ISO 8601 datetime
- `last_occurred_period` (optional): Preset period for last occurrence
- `last_occurred_from` (optional): ISO 8601 datetime
- `last_occurred_to` (optional): ISO 8601 datetime
- `occurrence_level` (optional): `critical`, `frequent`, `moderate`, `rare`
- `min_occurrences` (optional): Minimum count
- `max_occurrences` (optional): Maximum count
- `sort` (optional): `last_occurred`, `first_occurred`, `occurrence_count`
- `direction` (optional): `asc` or `desc`
- `limit` (optional): Results per page (default 25)
- `cursor` (optional): Pagination cursor
- `include_archived` (optional): Include soft-deleted errors

### restore-error

Restore a soft-deleted (archived) error.

**Parameters**:
- `error_id` (required): The error ID
- `type` (optional): `php` or `javascript`

### bulk-restore-errors

Restore multiple soft-deleted errors.

**Parameters**:
- `error_ids` (required): Array of error IDs (max 50)
- `type` (optional): `php` or `javascript`

---

## Database Changes Required

No additional database changes required for search functionality.

The soft delete migrations are already specified in the resolve-errors report:
- `deleted_at` column on `errors` table
- `deleted_at` column on `javascript_errors` table

---

## Implementation Notes

### Cursor Pagination Implementation

Cursors should encode:
1. The sort field being used
2. The last seen value for that field
3. The error ID (for tie-breaking)

Example cursor structure (base64 encoded):
```json
{
  "sort": "last_occurred",
  "value": "2026-01-23T12:34:56+00:00",
  "id": 123
}
```

### Merging PHP and JavaScript Errors

When `type=all`:
1. Query both tables with same filters
2. Merge results
3. Sort merged results
4. Apply cursor pagination on merged set

For performance, consider:
- Using UNION query if database supports it
- Fetching extra records from each table, merging, then trimming

### Filter Logic

All filters combine with AND logic:
```
status=open AND environment=production AND last_occurred_period=24h
```

Array values within a filter use OR logic:
```
status IN (open, snoozed) AND environment IN (production, staging)
```

---

## Files to Create/Modify

### New Files

1. `app/Http/Requests/V1/Mcp/SearchErrorsRequest.php`
2. `app/Http/Resources/V1/Mcp/SearchResultCollection.php`
3. `app/Services/ErrorSearchService.php` - Encapsulate search logic
4. `tests/Feature/Api/V1/Mcp/ErrorSearchTest.php`

### Modified Files

1. `app/Http/Controllers/Api/V1/Mcp/ErrorsController.php` - Enhance index method
2. `app/Http/Controllers/Api/V1/Mcp/ErrorActionsController.php` - Add restore action
3. `routes/api.php` - Add restore route

---

## Code Architecture

### Centralized Logic

All logic that is shared between MCP API requests and users via the UI must use centralized, shared code. No duplication is allowed.

**Requirements**:
- The `ErrorSearchService` should be the single source of search/filter logic
- Both MCP API controllers and UI controllers must use this service
- Cursor pagination logic should be reusable across contexts
- Filter building and query construction must not be duplicated
- Example: UI error list pages and MCP `search-errors` tool both call `ErrorSearchService::search()`

---

## Test Requirements

### Feature Tests

**Basic Filtering:**
- Search with no filters returns all errors
- Filter by type=php returns only PHP errors
- Filter by type=javascript returns only JS errors
- Filter by type=all returns both types with type indicator
- Filter by single status returns matching errors
- Filter by multiple statuses (array) returns matching errors
- Composite status 'active' returns open + snoozed
- Composite status 'closed' returns resolved + ignored

**Environment Filtering:**
- Filter by environments includes only listed
- Filter by exclude_environments excludes listed
- Cannot use both environments and exclude_environments (422)
- Environment filter is case-insensitive

**Date Filtering:**
- Filter by first_occurred_period works with presets
- Filter by last_occurred_period works with presets
- Custom date range with from/to works
- Custom range overrides preset if both provided
- Filter by both first and last occurred works

**Occurrence Count Filtering:**
- Filter by occurrence_level=critical returns >100
- Filter by occurrence_level=rare returns <5
- Filter by min_occurrences works
- Filter by max_occurrences works
- Combine min and max works
- Invalid min > max returns 422

**Sorting:**
- Default sort is last_occurred desc
- Sort by first_occurred works
- Sort by occurrence_count works
- Direction asc/desc works

**Pagination:**
- Default limit is 25
- Custom limit works (up to 100)
- Limit > 100 returns 422
- Cursor pagination returns correct next page
- Cursor pagination returns correct prev page
- No next_cursor on last page

**Aggregations:**
- Response includes total_count
- Response includes count_by_status
- Counts reflect full filtered set, not page

**Archived Errors:**
- Archived errors excluded by default
- include_archived=true includes archived
- Restore error works
- Restore already-active error succeeds (idempotent)
- Bulk restore works

**Combined Filters:**
- Multiple filters combine with AND logic
- Complex filter combinations work correctly
