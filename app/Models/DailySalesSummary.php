<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySalesSummary extends Model
{
    protected $fillable = [
        'sale_date',
        'successful_order_count',
        'total_quantity',
        'processing_mode',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'computed_at' => 'datetime',
        ];
    }
}
