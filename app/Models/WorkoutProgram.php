<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutProgram extends Model
{
    protected $fillable = ['client_id', 'cycle_number', 'day_label', 'exercises_json', 'is_active'];
    protected $casts = ['exercises_json' => 'array', 'is_active' => 'boolean'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
