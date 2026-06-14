<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySalesTallyChunk extends Model
{
    protected $fillable = [
        'sale_date',
        'batch_id',
        'chunk_index',
        'order_count',
        'total_quantity',
    ];

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
        ];
    }
}
