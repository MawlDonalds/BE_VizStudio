# Chat Session Integration with FastAPI

## Overview
This integration allows Laravel backend to work seamlessly with FastAPI's NL2SQL chat system. Both systems share the same PostgreSQL chat_history table created by LangChain's PostgresChatMessageHistory.

## Database Structure

### chat_sessions (Laravel managed)
```sql
CREATE TABLE chat_sessions (
    id_chat_session BIGSERIAL PRIMARY KEY,
    session_id UUID UNIQUE NOT NULL,          -- UUID for FastAPI compatibility
    user_id BIGINT NOT NULL,
    title VARCHAR(255) NOT NULL,
    datasource_id BIGINT NULL,                -- Links to datasources table
    created_by VARCHAR(255),
    created_at TIMESTAMP,
    modified_by VARCHAR(255),
    modified_at TIMESTAMP,
    
    FOREIGN KEY (datasource_id) REFERENCES datasources(id_datasource),
    INDEX idx_session_id (session_id),
    INDEX idx_user_session (user_id, session_id)
);
```

### chat_history (LangChain managed, shared with FastAPI)
```sql
CREATE TABLE chat_history (
    id SERIAL PRIMARY KEY,
    session_id UUID NOT NULL,                 -- Links to chat_sessions.session_id
    message JSONB NOT NULL,                   -- LangChain message format
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    INDEX idx_chat_history_session_id (session_id)
);
```

## LangChain Message Format

The `message` JSONB field contains:
```json
{
  "type": "human|ai|system",
  "data": {
    "id": null,
    "name": null,
    "type": "human",
    "content": "User message content or AI response",
    "additional_kwargs": {},
    "response_metadata": {}
  }
}
```

## API Endpoints

### Laravel Chat Session Management

1. **Create Session**
   ```
   POST /api/chat-sessions
   Body: { "title": "Session Title", "datasource_id": 12 }
   Response: { "session_id": "uuid", "id_chat_session": 123, ... }
   ```

2. **List User Sessions**
   ```
   GET /api/chat-sessions
   Response: { "data": [{ "session_id": "uuid", "title": "...", ... }] }
   ```

3. **Get Session History**
   ```
   GET /api/chat-sessions/{uuid}/history
   Response: { 
     "session": { "session_id": "uuid", ... },
     "messages": [{ "type": "human", "content": "...", "timestamp": "..." }]
   }
   ```

4. **Update Session**
   ```
   PUT /api/chat-sessions/{uuid}
   Body: { "title": "New Title" }
   ```

5. **Delete Session**
   ```
   DELETE /api/chat-sessions/{uuid}
   ```

6. **Clear History**
   ```
   DELETE /api/chat-sessions/{uuid}/history
   ```

7. **Get Session Stats**
   ```
   GET /api/chat-sessions/{uuid}/stats
   Response: {
     "stats": {
       "total_messages": 10,
       "human_messages": 5,
       "ai_messages": 5,
       "duration": "15 minutes"
     }
   }
   ```

### FastAPI NL2SQL Integration

The FastAPI system automatically:
- Uses UUID session_id format
- Saves messages to the shared chat_history table
- Maintains conversation context using LangChain's RunnableWithMessageHistory

## Model Usage Examples

### Laravel Models

```php
// Get session with chat history
$session = ChatSession::with('chatHistories')->find($id);

// Get formatted chat history
$formattedHistory = $session->getFormattedChatHistory();

// Query chat history directly
$messages = ChatHistory::bySession($sessionUuid)
    ->ordered()
    ->get();

// Get message statistics
$humanCount = ChatHistory::bySession($sessionUuid)
    ->whereJsonContains('message->type', 'human')
    ->count();
```

## Key Features

1. **UUID Compatibility**: Both systems use UUID session_id format
2. **Shared Database**: Same chat_history table used by LangChain/FastAPI
3. **Backward Compatibility**: Supports both UUID and integer ID lookups
4. **Message Parsing**: Automatic parsing of LangChain JSONB message format
5. **User Security**: All operations verify user ownership of sessions
6. **Transaction Safety**: Database operations use transactions for consistency

## Migration Notes

Run the migration to update existing chat_sessions table:
```bash
php artisan migrate
```

This will add:
- UUID session_id column with unique constraint
- datasource_id foreign key for NL2SQL integration  
- Performance indexes for session lookups

## Integration Flow

1. User creates chat session in Laravel frontend
2. Laravel generates UUID session_id and stores in chat_sessions
3. Frontend calls FastAPI NL2SQL endpoint with session_id
4. FastAPI saves conversation to shared chat_history table using UUID
5. Laravel can retrieve complete chat history using the same UUID
6. Both systems maintain consistent session state

This integration ensures seamless chat experience across Laravel frontend and FastAPI NL2SQL backend.