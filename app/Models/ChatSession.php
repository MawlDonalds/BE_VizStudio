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
        'user_id',
        'title',
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
     * Relationship with ChatHistory
     */
    public function chatHistories()
    {
        return $this->hasMany(ChatHistory::class, 'session_id', 'id_chat_session');
    }
}
