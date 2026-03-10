## Implementer Tasks

- [x] Read @spec/implement/backlog/create-notes.md and implement it, make sure to only implement the parts handled by this package (the `sorane-laravel` package), like the MCP servers that talk to Sorane via the API.
- [x] Read @spec/implement/backlog/resolve-errors.md and implement it, make sure to only implement the parts handled by this package (the `sorane-laravel` package), like the MCP servers that talk to Sorane via the API.
- [x] Read @spec/implement/backlog/search-errors.md and implement it, make sure to only implement the parts handled by this package (the `sorane-laravel` package), like the MCP servers that talk to Sorane via the API.

## Progress

### create-notes.md - COMPLETED
Implemented six MCP tools for managing investigation notes on errors:

**New MCP Tools:**
- `create-note` - Create a single note on an error (body max 5000 chars)
- `list-notes` - List notes with pagination and author filtering
- `get-note` - Get detailed information about a specific note
- `update-note` - Update an existing note (AI Agent notes only)
- `delete-note` - Archive a note (AI Agent notes only)
- `create-notes` - Bulk create up to 10 notes in one request

**API Client Methods Added:**
- `createNote(errorId, data)` - POST /errors/{id}/notes
- `listNotes(errorId, params)` - GET /errors/{id}/notes
- `getNote(errorId, noteId)` - GET /errors/{id}/notes/{noteId}
- `updateNote(errorId, noteId, data)` - PUT /errors/{id}/notes/{noteId}
- `deleteNote(errorId, noteId)` - DELETE /errors/{id}/notes/{noteId}
- `createNotesBulk(errorId, data)` - POST /errors/{id}/notes/bulk

**Features:**
- Error ID normalization (strips `err_` prefix)
- Note ID normalization (strips `note_` prefix)
- Body validation (max 5000 characters)
- Proper error handling (403, 404, 422)
- `IsReadOnly` annotations on list/get tools
- Retry logic with exponential backoff

**Tests:** 100+ new test cases covering all tools and API client methods

### resolve-errors.md - COMPLETED
Implemented eleven MCP tools for managing error states:

**Single Error Operation Tools:**
- `resolve-error` - Mark an error as resolved (idempotent)
- `reopen-error` - Move resolved error back to open state
- `ignore-error` - Permanently ignore an error (suppresses notifications)
- `unignore-error` - Remove ignore status from an error
- `snooze-error` - Temporarily snooze an error with duration presets or custom datetime
- `unsnooze-error` - Remove snooze from an error
- `delete-error` - Soft delete (archive) an error
- `get-error-activity` - View error state change history with pagination

**Bulk Operation Tools:**
- `bulk-resolve-errors` - Resolve multiple errors at once (max 50)
- `bulk-ignore-errors` - Ignore multiple errors at once (max 50)
- `bulk-delete-errors` - Delete multiple errors at once (max 50)

**API Client Methods Added:**
- `resolveError(errorId, type)` - POST /errors/{id}/resolve
- `reopenError(errorId, type)` - POST /errors/{id}/reopen
- `ignoreError(errorId, type)` - POST /errors/{id}/ignore
- `unignoreError(errorId, type)` - POST /errors/{id}/unignore
- `snoozeError(errorId, data, type)` - POST /errors/{id}/snooze
- `unsnoozeError(errorId, type)` - POST /errors/{id}/unsnooze
- `deleteError(errorId, type)` - DELETE /errors/{id}
- `getErrorActivity(errorId, params, type)` - GET /errors/{id}/activity
- `bulkResolveErrors(errorIds, type)` - POST /errors/bulk/resolve
- `bulkReopenErrors(errorIds, type)` - POST /errors/bulk/reopen
- `bulkIgnoreErrors(errorIds, type)` - POST /errors/bulk/ignore
- `bulkDeleteErrors(errorIds, type)` - POST /errors/bulk/delete

**Features:**
- Error ID normalization (strips `err_` prefix)
- Type parameter support (`php`, `javascript`, `js` alias)
- Snooze duration presets: `1h`, `8h`, `24h`, `7d`, `30d`
- Custom datetime validation for snooze (must be in future, ISO 8601 format)
- `IsReadOnly` annotation on get-error-activity tool
- Proper error handling (403, 404, 422)
- Retry logic with exponential backoff
- Bulk operations limited to 50 errors per request

**Tests:** 130+ new test cases covering all tools and API client methods

### search-errors.md - COMPLETED
Implemented three MCP tools for advanced error search and restoration:

**New MCP Tools:**
- `search-errors` - Advanced error search with filtering (type, status, environments, date ranges, occurrence levels, pagination)
- `restore-error` - Restore a soft-deleted (archived) error
- `bulk-restore-errors` - Restore multiple archived errors at once (max 50)

**API Client Methods Added:**
- `searchErrors(params)` - GET /errors with extensive query parameters
- `restoreError(errorId, type)` - POST /errors/{id}/restore
- `bulkRestoreErrors(errorIds, type)` - POST /errors/bulk/restore

**Search Features:**
- Filter by type (`php`, `javascript`)
- Filter by status (`open`, `resolved`, `ignored`, `snoozed`, `active`, `closed`)
- Filter by environments (include or exclude)
- Filter by date ranges (first_occurred, last_occurred with period presets)
- Filter by occurrence level (`critical`, `frequent`, `moderate`, `rare`)
- Filter by min/max occurrence counts
- Sorting by `last_occurred`, `first_occurred`, `occurrence_count`
- Cursor-based pagination (max 100 results per page)
- Include archived errors option
- Response includes total_count and count_by_status breakdown

**Features:**
- Error ID normalization (strips `err_` prefix)
- Type normalization (`js` → `javascript`)
- `IsReadOnly` annotation on search-errors tool
- Proper validation (min/max occurrences, limit, environments conflict)
- Proper error handling (403, 404, 422)
- Retry logic with exponential backoff

**Tests:** 80+ new test cases covering all tools and API client methods

