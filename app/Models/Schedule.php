<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    protected $fillable = [
        'client_id', 'workout_program_id', 'scheduled_date', 'scheduled_time', 'status',
        'started_at', 'finished_at', 'duration_minutes', 'actual_performance_json', 'coach_note', 'share_token',
    ];

    protected $casts = [
        'scheduled_date' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'actual_performance_json' => 'array',
    ];

    /**
     * Pecah teks target program, mis. "3 x 12" -> [3, "12"]; "2 x 10 /kaki" -> [2, "10 /kaki"].
     * Bila format tidak dikenali: [null, teks asli].
     */
    public static function parseSetsReps(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [null, null];
        }
        if (preg_match('/^(\d+)\s*[xX×]\s*(.+)$/u', $text, $m)) {
            return [(int) $m[1], trim($m[2])];
        }
        return [null, $text];
    }

    /** "12" / "10-12" -> "12 reps"; "30 detik" / "10 /kaki" dibiarkan apa adanya. */
    public static function repsLabel(?string $reps): string
    {
        $reps = trim((string) $reps);
        if ($reps === '') {
            return '';
        }
        return preg_match('/^[\d\s\-–]+$/u', $reps) ? $reps . ' reps' : $reps;
    }

    /**
     * Seragamkan satu item actual_performance_json (format baru: sets/reps/equipment,
     * format lama: hanya sets_reps teks) menjadi bentuk tunggal untuk WA, History, dan kartu progres.
     *
     * @return array{name:string, equipment:?string, sets:?int, reps:?string, kg:?float, sets_label:string, summary:string, line:string}
     */
    public static function normalizePerformance(array $p): array
    {
        $sets = isset($p['sets']) && $p['sets'] !== '' ? (int) $p['sets'] : null;
        $reps = isset($p['reps']) && trim((string) $p['reps']) !== '' ? trim((string) $p['reps']) : null;
        $legacy = trim((string) ($p['sets_reps'] ?? ''));
        if ($sets === null && $reps === null && $legacy !== '') {
            [$sets, $reps] = self::parseSetsReps($legacy);
        }

        $parts = [];
        if ($sets) {
            $parts[] = $sets . ' set';
        }
        if ($reps !== null) {
            $parts[] = self::repsLabel($reps);
        }
        $setsLabel = implode(' x ', $parts);

        $kg = isset($p['actual_kg']) && $p['actual_kg'] !== '' ? (float) $p['actual_kg'] : null;
        $kgText = ($kg !== null && $kg > 0) ? rtrim(rtrim(number_format($kg, 1, '.', ''), '0'), '.') . ' kg' : null;
        $summary = trim($setsLabel . ($kgText ? ($setsLabel ? ' @ ' : '@ ') . $kgText : ''));

        $equipment = trim((string) ($p['equipment'] ?? ''));
        $equipment = ($equipment === '' || $equipment === 'Tanpa Alat') ? null : $equipment;
        $name = (string) ($p['name'] ?? 'Gerakan');

        return [
            'name' => $name, 'equipment' => $equipment, 'sets' => $sets, 'reps' => $reps, 'kg' => $kg,
            'sets_label' => $setsLabel, 'summary' => $summary,
            // contoh: "Squat (Dumbbell): 3 set x 12 reps @ 10 kg"
            'line' => $name . ($equipment ? " ({$equipment})" : '') . ($summary !== '' ? ': ' . $summary : ''),
        ];
    }

    /** Item performa yang sudah diseragamkan untuk sesi ini. */
    public function normalizedPerformance(): array
    {
        return array_map(fn ($p) => self::normalizePerformance((array) $p), $this->actual_performance_json ?? []);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(WorkoutProgram::class, 'workout_program_id');
    }
}
