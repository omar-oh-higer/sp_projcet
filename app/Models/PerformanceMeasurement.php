<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerformanceMeasurement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'channel',
        'name',
        'duration_ms',
        'status_code',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_ms' => 'float',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
