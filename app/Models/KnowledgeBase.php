<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeBase extends Model
{
    use HasFactory;

    protected $table = 'knowledge_base';
    protected $fillable = [
        'id_datasource',
        'id_user',
        'entry_type',
        'term',
        'content',
        'embedding',
    ];

    public function datasource()
    {
        return $this->belongsTo(Datasource::class, 'id_datasource');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}