<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ExerciseMedia extends Model
{
    protected $table = 'exercise_media';
    protected $fillable = ['name', 'name_key', 'media_path', 'credit'];

    /** Ekstensi yang dirender sebagai video muter (loop); sisanya sebagai gambar/GIF. */
    public const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov'];

    /**
     * Kunci pencocokan nama gerakan: huruf kecil, semua run karakter
     * non-alfanumerik menjadi satu spasi. "Pec Deck" -> "pec deck",
     * "Push-Up" -> "push up". Wajib identik dengan mvKey() di JavaScript.
     */
    public static function keyFor(string $name): string
    {
        $key = preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim($name)));
        return trim(preg_replace('/\s+/', ' ', $key));
    }

    /** URL publik media gerakan (disk public), null bila belum ada media. */
    public function mediaUrl(): ?string
    {
        return $this->media_path ? Storage::disk('public')->url($this->media_path) : null;
    }

    /** 'video' untuk mp4/webm/mov, 'image' untuk gif/jpg/png/webp. */
    public function kind(): string
    {
        $ext = strtolower(pathinfo($this->media_path ?? '', PATHINFO_EXTENSION));
        return in_array($ext, self::VIDEO_EXTENSIONS, true) ? 'video' : 'image';
    }

    /**
     * Peta name_key => ['url' => ..., 'kind' => ..., 'credit' => ...], hanya gerakan yang punya media.
     * Dipakai halaman Klien (Lihat Program) & Kalender (live session).
     */
    public static function mediaMap(): array
    {
        return static::whereNotNull('media_path')->get()
            ->mapWithKeys(fn ($m) => [$m->name_key => ['url' => $m->mediaUrl(), 'kind' => $m->kind(), 'credit' => $m->credit]])
            ->all();
    }

    /**
     * Semua nama gerakan yang dikenal: dari baris exercise_media sendiri
     * ditambah nama-nama di program aktif semua klien. Terurut A-Z.
     * Dipakai daftar kelola Media Gerakan di halaman Alat Gym.
     */
    public static function knownNames(): array
    {
        $names = static::pluck('name')->all();
        WorkoutProgram::where('is_active', true)->pluck('exercises_json')->each(function ($exercises) use (&$names) {
            foreach ($exercises ?? [] as $x) {
                if (! empty($x['name'])) $names[] = (string) $x['name'];
            }
        });
        $byKey = [];
        foreach ($names as $n) {
            $byKey[static::keyFor($n)] ??= trim($n);
        }
        $out = array_values($byKey);
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }
}
