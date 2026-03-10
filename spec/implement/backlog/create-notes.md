# Create Notes - MCP Feature Brainstorm Report

## Overview

Add the ability to create investigation notes on errors via MCP, enabling AI conversations to document findings, root causes, and resolution steps directly attached to error records for team collaboration.

## Decisions Made

### 1. User Attribution

**Decision**: Single global AI Agent system user

- Create a single `AI Agent` user shared across all projects
- User details: `name: 'AI Agent'`, `email: ai@system.local`
- Created via database seeder/migration (not lazy-loaded)
- Identified by `is_system_user` boolean column on users table
- Hidden from team member lists and user management UI

**Requirements**:
- Add `is_system_user` boolean column to users table
- Create seeder to create the AI Agent user
- Update user listing queries to filter out system users
- Add `User::aiAgent()` helper method to retrieve the AI user

### 2. API Operations

**Decision**: Full CRUD support

| Operation | Endpoint | HTTP Method |
|-----------|----------|-------------|
| Create | `/mcp/v1/errors/{error_id}/notes` | POST |
| List | `/mcp/v1/errors/{error_id}/notes` | GET |
| Show | `/mcp/v1/errors/{error_id}/notes/{note_id}` | GET |
| Update | `/mcp/v1/errors/{error_id}/notes/{note_id}` | PUT/PATCH |
| Delete | `/mcp/v1/errors/{error_id}/notes/{note_id}` | DELETE |

**Requirements**:
- Create `NotesController` in `App\Http\Controllers\Api\V1\Mcp`
- Create Form Request classes for each operation
- Create API Resource classes for response formatting
- Register routes in `routes/api.php`

### 3. Content Format

**Decision**: Plain markdown only

- Accept a simple markdown `body` string
- Maximum length: 5,000 characters
- Supports existing markdown rendering via `renderedBody` accessor

**Requirements**:
- Validate `body` is required string, max 5000 characters
- Sanitize markdown to prevent XSS (already handled by `Str::markdown`)

### 4. Note Identification

**Decision**: Prefixed ID format (`note_123`)

- All note IDs returned and accepted use `note_` prefix
- Consistent with error ID format (`err_123`)

**Requirements**:
- Strip `note_` prefix when querying database
- Add `note_` prefix when returning in responses

### 5. Permissions

**Decision**: MCP can only modify its own notes

- Create: Can create notes on any error belonging to authenticated project
- Read/List: Can read all notes on errors belonging to authenticated project
- Update: Can only update notes created by AI Agent user AND belonging to authenticated project
- Delete: Can only delete notes created by AI Agent user AND belonging to authenticated project

**Requirements**:
- Check `user_id` matches AI Agent user for update/delete operations
- Check that the error (and thus the note) belongs to the authenticated project for update/delete operations
- Return 403 if trying to modify another user's note or a note from another project

### 6. Delete Behavior

**Decision**: Archive via SoftDeletes

- Use Laravel's `SoftDeletes` trait on `ErrorComment` model
- Archived notes hidden by default from list endpoint
- Support `?include_archived=true` query parameter to include archived notes

**Requirements**:
- Add migration for `deleted_at` column on `error_comments` table
- Add `SoftDeletes` trait to `ErrorComment` model
- Add filter logic in list endpoint

### 7. List Behavior

**Decision**: Newest first with pagination and filters

- Default ordering: newest first (created_at DESC)
- Default limit: 50 notes
- Support offset pagination
- Filters supported:
  - `author`: Filter by user_id (e.g., `author=ai` for AI notes only)
  - `from` / `to`: Filter by created_at date range

**Requirements**:
- Add pagination parameters: `limit`, `offset`
- Add filter parameters: `author`, `from`, `to`
- Return total count in response metadata

### 8. Bulk Create

**Decision**: Support bulk creation with limits

- Allow creating multiple notes in single request
- Maximum: 10 notes per request
- Transaction behavior: All-or-nothing (rollback on any failure)

**Requirements**:
- Accept `notes` array in request body
- Wrap creation in database transaction
- Return array of created notes on success
- Return validation errors for entire batch on failure

### 9. Response Formats

**Decision**: ISO 8601 timestamps, minimal context

- Timestamps in ISO 8601 format: `2026-01-23T12:34:56+00:00`
- Show endpoint returns only `error_id` for context (not full error details)
- User info: Name only (not email or avatar)

**Requirements**:
- Format dates using `->toIso8601String()`
- Include `author_name` field in note responses
- Include `error_id` (prefixed) in single note responses

### 10. Error Handling

**Decision**: Standard HTTP status codes

| Scenario | Status | Response |
|----------|--------|----------|
| Error not found | 404 | `{ error: { code: "not_found", message: "..." } }` |
| Note not found | 404 | `{ error: { code: "not_found", message: "..." } }` |
| Cross-project access | 403 | `{ error: { code: "forbidden", message: "..." } }` |
| Cannot modify other's note | 403 | `{ error: { code: "forbidden", message: "..." } }` |
| Validation failure | 422 | Standard Laravel validation errors |

### 11. Rate Limiting

**Decision**: Use existing throttle

- Rely on existing `throttle:mcp-api` middleware
- No additional note-specific rate limiting

### 12. MCP Tool Naming

**Decision**: Hyphenated lowercase

- `create-note` - Create a single note
- `list-notes` - List notes on an error
- `get-note` - Get a specific note
- `update-note` - Update a note
- `delete-note` - Delete (archive) a note
- `create-notes` - Bulk create notes

---

## API Endpoint Specifications

### POST /mcp/v1/errors/{error_id}/notes

Create a single note on an error.

**Request Body**:
```json
{
  "body": "Investigation notes in markdown format..."
}
```

**Response** (201 Created):
```json
{
  "note": {
    "id": "note_456",
    "error_id": "err_123",
    "body": "Investigation notes in markdown format...",
    "author_name": "AI Agent",
    "created_at": "2026-01-23T12:34:56+00:00",
    "updated_at": "2026-01-23T12:34:56+00:00"
  }
}
```

### POST /mcp/v1/errors/{error_id}/notes/bulk

Create multiple notes in a single request.

**Request Body**:
```json
{
  "notes": [
    { "body": "First note..." },
    { "body": "Second note..." }
  ]
}
```

**Response** (201 Created):
```json
{
  "notes": [
    {
      "id": "note_456",
      "error_id": "err_123",
      "body": "First note...",
      "author_name": "AI Agent",
      "created_at": "2026-01-23T12:34:56+00:00",
      "updated_at": "2026-01-23T12:34:56+00:00"
    },
    {
      "id": "note_457",
      "error_id": "err_123",
      "body": "Second note...",
      "author_name": "AI Agent",
      "created_at": "2026-01-23T12:34:56+00:00",
      "updated_at": "2026-01-23T12:34:56+00:00"
    }
  ]
}
```

### GET /mcp/v1/errors/{error_id}/notes

List notes on an error.

**Query Parameters**:
- `limit` (int, default 50): Maximum notes to return
- `offset` (int, default 0): Number of notes to skip
- `author` (string): Filter by author (`ai` for AI Agent, or user ID)
- `from` (string): ISO 8601 date, notes created after
- `to` (string): ISO 8601 date, notes created before
- `include_archived` (bool, default false): Include archived notes

**Response** (200 OK):
```json
{
  "notes": [
    {
      "id": "note_456",
      "body": "Investigation notes...",
      "author_name": "AI Agent",
      "created_at": "2026-01-23T12:34:56+00:00",
      "updated_at": "2026-01-23T12:34:56+00:00",
      "archived": false
    }
  ],
  "meta": {
    "total": 25,
    "limit": 50,
    "offset": 0
  }
}
```

### GET /mcp/v1/errors/{error_id}/notes/{note_id}

Get a specific note.

**Response** (200 OK):
```json
{
  "note": {
    "id": "note_456",
    "error_id": "err_123",
    "body": "Investigation notes...",
    "author_name": "AI Agent",
    "created_at": "2026-01-23T12:34:56+00:00",
    "updated_at": "2026-01-23T12:34:56+00:00",
    "archived": false
  }
}
```

### PUT /mcp/v1/errors/{error_id}/notes/{note_id}

Update a note (AI Agent notes only).

**Request Body**:
```json
{
  "body": "Updated investigation notes..."
}
```

**Response** (200 OK):
```json
{
  "note": {
    "id": "note_456",
    "error_id": "err_123",
    "body": "Updated investigation notes...",
    "author_name": "AI Agent",
    "created_at": "2026-01-23T12:34:56+00:00",
    "updated_at": "2026-01-23T12:35:00+00:00"
  }
}
```

### DELETE /mcp/v1/errors/{error_id}/notes/{note_id}

Archive a note (AI Agent notes only).

**Response** (200 OK):
```json
{
  "message": "Note archived successfully"
}
```

---

## Database Changes Required

### Migration: Add is_system_user to users table

```php
Schema::table('users', function (Blueprint $table) {
    $table->boolean('is_system_user')->default(false)->after('email');
});
```

### Migration: Add soft deletes to error_comments table

```php
Schema::table('error_comments', function (Blueprint $table) {
    $table->softDeletes();
});
```

### Seeder: Create AI Agent user

```php
User::firstOrCreate(
    ['email' => 'ai@system.local'],
    [
        'name' => 'AI Agent',
        'is_system_user' => true,
        'password' => Hash::make(Str::random(64)),
        'email_verified_at' => now(),
    ]
);
```

---

## sorane-laravel Package Implementation Notes

The sorane-laravel package should implement these MCP tools:

### create-note

Creates a note on an error.

**Parameters**:
- `error_id` (required): The error ID (with or without `err_` prefix)
- `body` (required): The markdown content of the note

### list-notes

Lists notes on an error.

**Parameters**:
- `error_id` (required): The error ID
- `limit` (optional): Max notes to return (default 50)
- `author` (optional): Filter by author (`ai` or user ID)

### get-note

Gets a specific note.

**Parameters**:
- `error_id` (required): The error ID
- `note_id` (required): The note ID (with or without `note_` prefix)

### update-note

Updates a note (AI-created notes only).

**Parameters**:
- `error_id` (required): The error ID
- `note_id` (required): The note ID
- `body` (required): The new markdown content

### delete-note

Archives a note (AI-created notes only).

**Parameters**:
- `error_id` (required): The error ID
- `note_id` (required): The note ID

### create-notes

Bulk creates multiple notes.

**Parameters**:
- `error_id` (required): The error ID
- `notes` (required): Array of note objects with `body` field (max 10)

---

## Files to Create/Modify

### New Files

1. `app/Http/Controllers/Api/V1/Mcp/NotesController.php`
2. `app/Http/Requests/V1/Mcp/CreateNoteRequest.php`
3. `app/Http/Requests/V1/Mcp/BulkCreateNotesRequest.php`
4. `app/Http/Requests/V1/Mcp/UpdateNoteRequest.php`
5. `app/Http/Requests/V1/Mcp/ListNotesRequest.php`
6. `app/Http/Resources/V1/Mcp/NoteResource.php`
7. `app/Http/Resources/V1/Mcp/NoteCollection.php`
8. `database/migrations/xxxx_add_is_system_user_to_users_table.php`
9. `database/migrations/xxxx_add_soft_deletes_to_error_comments_table.php`
10. `database/seeders/AiAgentUserSeeder.php`
11. `tests/Feature/Api/V1/Mcp/NotesControllerTest.php`

### Modified Files

1. `app/Models/ErrorComment.php` - Add SoftDeletes trait
2. `app/Models/User.php` - Add `aiAgent()` static method, add `is_system_user` cast
3. `routes/api.php` - Add notes routes
4. `database/seeders/DatabaseSeeder.php` - Call AiAgentUserSeeder

---

## Code Architecture

### Centralized Logic

All logic that is shared between MCP API requests and users via the UI must use centralized, shared code. No duplication is allowed.

**Requirements**:
- Extract shared business logic into Service classes or Actions
- Controllers (both API and web) should delegate to these shared services
- Validation rules that apply to both contexts should be reusable
- Example: Note creation logic should live in a `NoteService` or `CreateNoteAction` class that both the MCP `NotesController` and any UI controllers call

---

## Test Requirements

### Unit Tests

- `User::aiAgent()` returns the AI Agent user
- `User::aiAgent()` creates user if not exists (optional fallback)
- System users are excluded from team member scopes

### Feature Tests

- Create note on own project's error - 201
- Create note on other project's error - 403
- Create note on non-existent error - 404
- Create note with empty body - 422
- Create note with body exceeding 5000 chars - 422
- List notes on error - 200 with pagination
- List notes with author filter - 200 with filtered results
- List notes with date range filter - 200 with filtered results
- List notes with include_archived - 200 includes archived
- Get single note - 200
- Get non-existent note - 404
- Update own AI note - 200
- Update another user's note - 403
- Delete own AI note - 200 (archives)
- Delete another user's note - 403
- Bulk create notes - 201
- Bulk create with one invalid - 422 and none created
- Bulk create exceeding limit (>10) - 422
