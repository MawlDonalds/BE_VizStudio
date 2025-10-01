<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatSession extends Model
{
    use HasFactory;

    protected $table = 'chat_sessions';
    protected $primaryKey = 'id_chat_session';
    public $timestamps = false;
    
    protected $fillable = [
        'session_id',        // UUID string for FastAPI compatibility
        'user_id',
        'title',
        'datasource_id',     // Link to datasource for NL2SQL
        'created_by',
        'created_at',
        'modified_by',
        'modified_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'modified_at' => 'datetime',
    ];

    /**
     * Relationship with User
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id_user');
    }

    /**
     * Relationship with ChatHistory (using UUID session_id)
     * Uses the same chat_history table as FastAPI/LangChain
     */
    public function chatHistories()
    {
        return $this->hasMany(ChatHistory::class, 'session_id', 'session_id');
    }

    /**
     * Get formatted chat history with parsed messages
     */
    public function getFormattedChatHistory()
    {
        return $this->chatHistories()->ordered()->get()->map(function ($history) {
            return [
                'id' => $history->id,
                'type' => $history->message_type,
                'content' => $history->message_content,
                'timestamp' => $history->created_at,
                'raw_message' => $history->message // Include full LangChain message if needed
            ];
        });
    }

    /**
     * Relationship with Datasource
     */
    public function datasource()
    {
        return $this->belongsTo(\App\Models\Datasource::class, 'datasource_id', 'id_datasource');
    }
}
