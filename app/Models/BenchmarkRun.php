<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenchmarkRun extends Model
{
    protected $fillable = [
        'trace_id',
        'mode',
        'product_id',
        'total_duration_ms',
        'db_queries',
        'bottleneck_span',
        'spans',
    ];

    protected function casts(): array
    {
        return [
            'total_duration_ms' => 'float',
            'spans' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
