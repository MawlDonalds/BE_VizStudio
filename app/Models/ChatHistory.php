<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatHistory extends Model
{
    use HasFactory;

    protected $connection = 'pgsql'; // Use PostgreSQL connection
    protected $table = 'chat_history';
    protected $primaryKey = 'id';
    public $timestamps = false; // Using custom created_at field
    
    protected $fillable = [
        'session_id',  // UUID
        'message',     // JSONB field containing LangChain message format
        'created_at'   // Timestamp with timezone
    ];

    protected $casts = [
        'session_id' => 'string',  // UUID as string
        'message' => 'array',      // JSONB as array
        'created_at' => 'datetime',
    ];

    /**
     * Relationship with ChatSession (using UUID session_id)
     */
    public function chatSession()
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'session_id');
    }

    /**
     * Get message type from JSONB message field
     */
    public function getMessageTypeAttribute()
    {
        return $this->message['type'] ?? 'unknown';
    }

    /**
     * Get message content from JSONB message field
     */
    public function getMessageContentAttribute()
    {
        return $this->message['data']['content'] ?? '';
    }

    /**
     * Scope to filter by session UUID
     */
    public function scopeBySession($query, $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope to order by creation time
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('created_at', 'asc');
    }
}
