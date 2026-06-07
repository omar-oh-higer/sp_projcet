<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoadDistributionHit extends Model
{
    protected $fillable = [
        'target_server',
        'distribution_mode',
        'request_index',
    ];
}
