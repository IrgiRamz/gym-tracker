<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeightLog extends Model
{
    protected $fillable = ['client_id', 'weight_kg', 'recorded_at'];
    protected $casts = ['recorded_at' => 'date:Y-m-d'];
}
