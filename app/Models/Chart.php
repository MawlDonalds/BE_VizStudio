<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chart extends Model
{
    use HasFactory;

    protected $table = 'chart';
    protected $primaryKey = 'id_chart';
    public $timestamps = false;
    protected $fillable = [
        'id_canvas',
        'id_datasources',
        'name',
        'chart_type',
        'query',
        'config',
        'width',
        'height',
        'position_x',
        'position_y',
        'created_by',
        'created_time',
        'modified_by',
        'modified_time',
        'is_deleted'
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function datasource()
    {
        return $this->belongsTo(Datasource::class, 'id_datasource');
    }

    public function kanvas()
    {
        return $this->belongsTo(Canvas::class, 'id_canvas');
    }
}
