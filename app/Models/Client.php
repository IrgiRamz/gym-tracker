<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'name', 'phone', 'gender', 'age', 'weight_kg', 'height_cm', 'waist_cm', 'goal', 'goal_note', 'body_focus',
        'pt_frequency_per_week', 'activity_level', 'remaining_sessions',
        'daily_protein_min', 'daily_protein_max', 'daily_protein_target', 'notes',
    ];

    public const GOALS = ['Bentuk & Kencangkan', 'Turun Berat Badan', 'Naik Massa Otot', 'Bugar & Sehat', 'Persiapan Lari / Marathon'];
    public const FOCUSES = ['Bokong (Glutes)', 'Kaki & Paha', 'Dada (Chest)', 'Punggung (Back)', 'Lengan & Bahu', 'Perut & Pinggang', 'Seluruh Tubuh'];

    protected $casts = ['body_focus' => 'array'];

    /** Fokus tubuh sebagai teks, mis. "Bokong (Glutes), Punggung (Back)". */
    public function focusText(): string
    {
        return implode(', ', $this->body_focus ?? []);
    }

    /** Jika "Seluruh Tubuh" dipilih, abaikan pilihan spesifik lainnya. */
    public static function normalizeFocus(array $focus): array
    {
        $focus = array_values(array_unique(array_intersect(self::FOCUSES, $focus)));
        return in_array('Seluruh Tubuh', $focus, true) ? ['Seluruh Tubuh'] : $focus;
    }

    public function programs(): HasMany
    {
        return $this->hasMany(WorkoutProgram::class);
    }

    public function activePrograms(): HasMany
    {
        return $this->hasMany(WorkoutProgram::class)->where('is_active', true)->orderBy('id');
    }

    public function weightLogs(): HasMany
    {
        return $this->hasMany(WeightLog::class)->orderBy('recorded_at')->orderBy('id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Matriks pengali protein (gram per kg berat badan) [min, max]:
     * tujuan => gender => [tier 1 (1x/minggu), tier 2 (2x/minggu), tier 3 (3x+/minggu)].
     * Sumber tunggal: dipakai backend dan dikirim ke JavaScript untuk kalkulator real-time.
     */
    public const PROTEIN_MATRIX = [
        'Naik Massa Otot' => [
            'Pria' => [[1.6, 1.8], [1.8, 2.0], [2.0, 2.2]],
            'Wanita' => [[1.4, 1.6], [1.6, 1.8], [1.8, 2.0]],
        ],
        'Turun Berat Badan' => [
            'Pria' => [[1.6, 1.9], [1.8, 2.1], [2.0, 2.3]],
            'Wanita' => [[1.4, 1.7], [1.6, 1.9], [1.8, 2.1]],
        ],
        'Bentuk & Kencangkan' => [
            'Pria' => [[1.5, 1.7], [1.7, 1.9], [1.8, 2.1]],
            'Wanita' => [[1.3, 1.5], [1.5, 1.7], [1.6, 1.9]],
        ],
        'Bugar & Sehat' => [
            'Pria' => [[1.2, 1.4], [1.4, 1.6], [1.5, 1.8]],
            'Wanita' => [[1.0, 1.2], [1.2, 1.4], [1.4, 1.6]],
        ],
        // Daya tahan (lari jauh/marathon): protein sedang, naik seiring beban latihan.
        'Persiapan Lari / Marathon' => [
            'Pria' => [[1.2, 1.4], [1.4, 1.6], [1.6, 1.8]],
            'Wanita' => [[1.1, 1.3], [1.3, 1.5], [1.5, 1.7]],
        ],
    ];

    /** Keaktifan di luar sesi PT => penyesuaian tier (-1, 0, +1). */
    public const ACTIVITIES = [
        'Baru Mulai / Santai' => -1,
        'Cukup Aktif' => 0,
        'Sangat Aktif' => 1,
    ];

    /** Tier 1-3 dari frekuensi sesi PT per minggu, disesuaikan keaktifan di luar sesi PT. */
    public static function proteinTier(int $frequency, string $activity = 'Cukup Aktif'): int
    {
        $base = max(1, min(3, $frequency));
        return max(1, min(3, $base + (self::ACTIVITIES[$activity] ?? 0)));
    }

    /** Pengali [min, max] gram per kg. */
    public static function proteinPerKgRange(string $goal, string $gender, int $frequency, string $activity = 'Cukup Aktif'): array
    {
        $byGender = self::PROTEIN_MATRIX[$goal] ?? self::PROTEIN_MATRIX['Bugar & Sehat'];
        $rows = $byGender[$gender] ?? $byGender['Wanita'];
        return $rows[self::proteinTier($frequency, $activity) - 1];
    }

    /**
     * Rentang protein harian [min, max] dalam gram, dibulatkan ke bilangan bulat terdekat.
     * Dihitung dengan aritmetika bilangan bulat (x10) agar hasilnya identik dengan JavaScript.
     */
    public static function calcProteinRange(float $weight, string $goal, string $gender, int $frequency, string $activity = 'Cukup Aktif'): array
    {
        [$min, $max] = self::proteinPerKgRange($goal, $gender, $frequency, $activity);
        $w10 = (int) round($weight * 10);
        $calc = fn (float $perKg) => (int) floor(($w10 * (int) round($perKg * 10)) / 100 + 0.5);
        return [$calc($min), $calc($max)];
    }

    /** "75g - 90g" untuk ditampilkan. */
    public function proteinRangeText(string $sep = ' – '): string
    {
        return $this->daily_protein_min && $this->daily_protein_max
            ? "{$this->daily_protein_min}g{$sep}{$this->daily_protein_max}g"
            : "{$this->daily_protein_target}g";
    }

    /** Konversi praktis protein ke makanan harian. */
    public static function proteinFoodHint(int $grams): string
    {
        // dada ayam 31 g/100 g, telur 6 g/butir, tempe 19 g/100 g
        $ayam = (int) (round(($grams * 0.45 / 31 * 100) / 10) * 10);
        $telur = max(1, (int) round($grams * 0.2 / 6));
        $tempe = (int) (round(($grams * 0.15 / 19 * 100) / 10) * 10);
        return "kira-kira {$ayam} g dada ayam + {$telur} butir telur + {$tempe} g tempe (sisanya dari nasi, susu, dan sayur)";
    }
}
