<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'stock'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}