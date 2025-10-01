# Chat Session API Documentation

## Overview
Chat Session API telah diperbarui untuk kompatibilitas penuh dengan FastAPI Service. API ini mendukung manajemen sesi chat dengan integrasi NL2SQL dan PostgresChatMessageHistory.

## Key Changes for FastAPI Compatibility

### 1. Session ID Format
- **FastAPI**: Menggunakan UUID string (e.g., `"7c9541aa-19e2-4362-befb-a97ded7d92ed"`)
- **Laravel**: Sekarang mendukung UUID string + backward compatibility dengan integer ID

### 2. Database Schema Updates
```sql
-- New columns added to chat_sessions table
ALTER TABLE chat_sessions ADD COLUMN session_id UUID UNIQUE;
ALTER TABLE chat_sessions ADD COLUMN datasource_id BIGINT REFERENCES datasources(id_datasource);
```

### 3. Chat History Integration
- Laravel sekarang membaca dari tabel `chat_history` yang sama dengan FastAPI
- Mendukung format LangChain PostgresChatMessageHistory
- Parsing message format LangChain: `{"type": "human/ai", "data": {"content": "..."}}`

## API Endpoints

### POST /api/chat-sessions
Create new chat session with UUID support
```json
{
    "title": "New Chat Session",
    "datasource_id": 12
}
```

Response:
```json
{
    "status": "success",
    "data": {
        "session_id": "7c9541aa-19e2-4362-befb-a97ded7d92ed",  // UUID for FastAPI
        "id_chat_session": 123,                                 // Integer for Laravel
        "title": "New Chat Session",
        "datasource_id": 12,
        "user_id": 1,
        "created_at": "2024-09-24T10:00:00Z"
    }
}
```

### GET /api/chat-sessions
List all user sessions with UUID support
```json
{
    "status": "success",
    "data": [
        {
            "id_chat_session": 123,
            "session_id": "7c9541aa-19e2-4362-befb-a97ded7d92ed",
            "title": "Chat Session 1",
            "datasource_id": 12,
            "created_at": "2024-09-24T10:00:00Z",
            "modified_at": "2024-09-24T10:00:00Z"
        }
    ]
}
```

### GET /api/chat-sessions/{sessionId}/history
Get chat history (supports both UUID and integer ID)
```json
{
    "status": "success",
    "data": {
        "session": {
            "id": 123,
            "session_id": "7c9541aa-19e2-4362-befb-a97ded7d92ed",
            "title": "Chat Session 1",
            "datasource_id": 12,
            "created_at": "2024-09-24T10:00:00Z"
        },
        "messages": [
            {
                "id": 1,
                "type": "human",
                "content": "Tampilkan semua data pengguna",
                "timestamp": "2024-09-24T10:01:00Z"
            },
            {
                "id": 2,
                "type": "ai", 
                "content": "SELECT * FROM users;",
                "timestamp": "2024-09-24T10:01:05Z"
            }
        ]
    }
}
```

### GET /api/chat-sessions/uuid/{sessionUuid}
Get or create session by UUID (for FastAPI integration)
```json
{
    "status": "success",
    "data": {
        "session_id": "7c9541aa-19e2-4362-befb-a97ded7d92ed",
        "id_chat_session": 123,
        "title": "Chat Session 1",
        "datasource_id": 12,
        "user_id": 1,
        "created_at": "2024-09-24T10:00:00Z",
        "is_new": false
    }
}
```

### PUT /api/chat-sessions/{sessionId}
Update session (supports both UUID and integer ID)
```json
{
    "title": "Updated Session Title"
}
```

### DELETE /api/chat-sessions/{sessionId}
Delete session and all history (supports both UUID and integer ID)
- Deletes from LangChain `chat_history` table
- Deletes from legacy Laravel `chat_history` table
- Deletes session record

### POST /api/chat-sessions/{sessionId}/clear
Clear session history while keeping session
- Clears LangChain `chat_history` table
- Clears legacy Laravel `chat_history` table
- Keeps session record

## Integration Flow

### 1. Frontend → Laravel API
1. User creates session via Laravel API
2. Get session UUID from response
3. Pass UUID to FastAPI for NL2SQL operations

### 2. FastAPI → PostgresChatMessageHistory
1. FastAPI receives UUID session_id
2. Uses PostgresChatMessageHistory with UUID
3. Messages saved to `chat_history` table

### 3. Laravel API → Chat History
1. Laravel reads same `chat_history` table
2. Parses LangChain message format
3. Returns structured chat history

## Migration Required

Run migration to update database schema:
```bash
php artisan migrate
```

This adds:
- `session_id` UUID column to `chat_sessions`
- `datasource_id` foreign key to `datasources`
- Unique indexes for performance
- Auto-generates UUIDs for existing sessions

## Backward Compatibility

All endpoints support both UUID and integer session IDs:
- Use UUID for FastAPI integration
- Use integer ID for legacy Laravel features
- Frontend can use either format seamlessly