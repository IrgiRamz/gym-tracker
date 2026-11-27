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
     * Seragamkan satu item actual_performance_json menjadi bentuk tunggal untuk History dan kartu progres.
     * Mendukung tiga format: per-set (sets_detail: repetisi & beban tiap set), agregat (sets/reps/actual_kg),
     * dan lama (hanya sets_reps teks). Untuk format per-set: sets = jumlah baris set, kg = beban terberat
     * (top set, dipakai lencana & delta progres), reps hanya terisi bila semua set sama.
     *
     * @return array{name:string, equipment:?string, sets:?int, reps:?string, kg:?float, sets_label:string, summary:string, line:string, sets_detail:array, detail_label:?string}
     */
    public static function normalizePerformance(array $p): array
    {
        // --- Format per-set: sets_detail = [{reps, kg}, ...] ---
        $detail = [];
        if (! empty($p['sets_detail']) && is_array($p['sets_detail'])) {
            foreach ($p['sets_detail'] as $sd) {
                $detail[] = [
                    'reps' => isset($sd['reps']) && trim((string) $sd['reps']) !== '' ? trim((string) $sd['reps']) : null,
                    'kg' => isset($sd['kg']) && $sd['kg'] !== '' && $sd['kg'] !== null ? (float) $sd['kg'] : 0.0,
                ];
            }
        }

        if ($detail) {
            $sets = count($detail);
            $repsValues = array_values(array_unique(array_filter(array_column($detail, 'reps'))));
            $reps = count($repsValues) === 1 ? $repsValues[0] : null;
            $kg = max(array_column($detail, 'kg'));
        } else {
            $sets = isset($p['sets']) && $p['sets'] !== '' ? (int) $p['sets'] : null;
            $reps = isset($p['reps']) && trim((string) $p['reps']) !== '' ? trim((string) $p['reps']) : null;
            $legacy = trim((string) ($p['sets_reps'] ?? ''));
            if ($sets === null && $reps === null && $legacy !== '') {
                [$sets, $reps] = self::parseSetsReps($legacy);
            }
            $kg = isset($p['actual_kg']) && $p['actual_kg'] !== '' ? (float) $p['actual_kg'] : null;
        }

        // Gerakan berbasis waktu (Plank): kolom "reps" berisi durasi detik, tanpa beban.
        $timed = ! empty($p['timed']);
        $parts = [];
        if ($sets) {
            $parts[] = $sets . ' set';
        }
        if ($reps !== null) {
            $parts[] = $timed
                ? (str_contains(strtolower($reps), 'detik') ? $reps : $reps . ' detik')
                : self::repsLabel($reps);
        }
        $setsLabel = implode(' x ', $parts);

        $kgText = ($kg !== null && $kg > 0) ? rtrim(rtrim(number_format($kg, 1, '.', ''), '0'), '.') . ' kg' : null;
        // Untuk per-set, beban di ringkasan adalah top set -> tandai "maks".
        $summary = trim($setsLabel . ($kgText ? ($setsLabel ? ' @ ' : '@ ') . ($detail ? 'maks ' : '') . $kgText : ''));

        $equipment = trim((string) ($p['equipment'] ?? ''));
        $equipment = ($equipment === '' || $equipment === 'Tanpa Alat') ? null : $equipment;
        $name = (string) ($p['name'] ?? 'Gerakan');

        // Label ringkas per set, contoh: "S1 12x5kg · S2 10x7.5kg · S3 8x10kg".
        // Hanya tampil bila minimal satu set benar-benar terisi; semua set kosong -> null (hindari "S1 -").
        $detailLabel = null;
        $anyDetailData = false;
        foreach ($detail as $sd) {
            if ($sd['reps'] !== null || $sd['kg'] > 0) {
                $anyDetailData = true;
                break;
            }
        }
        if ($detail && $anyDetailData) {
            $detailLabel = implode(' · ', array_map(function ($sd, $i) use ($timed) {
                $kgFmt = rtrim(rtrim(number_format($sd['kg'], 1, '.', ''), '0'), '.') . 'kg';
                $t = 'S' . ($i + 1);
                if ($timed) {
                    $t .= $sd['reps'] !== null
                        ? ' ' . (str_contains(strtolower($sd['reps']), 'detik') ? $sd['reps'] : $sd['reps'] . ' detik')
                        : ' -';
                } elseif ($sd['reps'] !== null && $sd['kg'] > 0) {
                    $t .= ' ' . $sd['reps'] . 'x' . $kgFmt;
                } elseif ($sd['reps'] !== null) {
                    $t .= ' ' . $sd['reps'];
                } elseif ($sd['kg'] > 0) {
                    $t .= ' ' . $kgFmt;
                } else {
                    $t .= ' -';
                }
                return $t;
            }, $detail, array_keys($detail)));
        }

        return [
            'name' => $name, 'equipment' => $equipment, 'sets' => $sets, 'reps' => $reps, 'kg' => $kg,
            'sets_label' => $setsLabel, 'summary' => $summary,
            // contoh: "Squat (Dumbbell): 3 set x 12 reps @ 10 kg"
            'line' => $name . ($equipment ? " ({$equipment})" : '') . ($summary !== '' ? ': ' . $summary : ''),
            'sets_detail' => $detail, 'detail_label' => $detailLabel, 'timed' => $timed,
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
