<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Equipment extends Model
{
    protected $table = 'equipments';
    protected $fillable = ['name', 'category', 'is_available'];
    protected $casts = ['is_available' => 'boolean'];

    public const CATEGORIES = ['Beban Bebas', 'Mesin Latihan', 'Kardio & Matras', 'Tanpa Alat'];
}
