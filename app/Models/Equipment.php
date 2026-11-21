<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Equipment extends Model
{
    protected $table = 'equipments';
    protected $fillable = ['name', 'category', 'is_available', 'image_path'];
    protected $casts = ['is_available' => 'boolean'];

    public const CATEGORIES = ['Beban Bebas', 'Mesin Latihan', 'Kardio & Matras', 'Tanpa Alat'];

    /** URL publik foto alat (disk public), null bila belum ada foto. */
    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Peta nama alat => URL foto, hanya untuk alat yang punya foto. Dipakai halaman Klien & Kalender. */
    public static function imageMap(): array
    {
        return static::whereNotNull('image_path')->get()
            ->mapWithKeys(fn ($e) => [$e->name => $e->imageUrl()])->all();
    }
}
