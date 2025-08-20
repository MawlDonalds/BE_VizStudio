<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatHistory extends Model
{
    use HasFactory;

    protected $table = 'chat_history';
    protected $primaryKey = 'id_history';
    public $timestamps = false;
    
    protected $fillable = [
        'session_id',
        'history',
        'created_by',
        'created_at',
        'modified_by',
        'modified_at'
    ];

    protected $casts = [
        'history' => 'array',
        'created_at' => 'datetime',
        'modified_at' => 'datetime',
    ];

    /**
     * Relationship with ChatSession
     */
    public function chatSession()
    {
        return $this->belongsTo(ChatSession::class, 'session_id', 'id_chat_session');
    }
}
