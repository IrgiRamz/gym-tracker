<?php

namespace App\Services;

use App\Models\Equipment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiWorkoutService
{
    /** Jumlah gerakan per sesi (sudah termasuk pemanasan) */
    private const MIN_EXERCISES = 4;
    private const MAX_EXERCISES = 5;

    /**
     * Hasilkan program latihan: array of ['day_label' => string, 'exercises' => [...]].
     * Selalu mengembalikan hasil (fallback template jika AI tidak tersedia).
     *
     * @return array{programs: array, source: string}
     */
    public function generate(array $client): array
    {
        $equipments = Equipment::where('is_available', true)->pluck('name')->all();
        $freq = (int) $client['pt_frequency_per_week'];

        if (filled(config('services.gemini.key'))) {
            try {
                $programs = $this->callGemini($client, $equipments, $freq);
                if ($programs) {
                    return ['programs' => $programs, 'source' => 'ai'];
                }
            } catch (Throwable $e) {
                Log::warning('Gemini gagal: ' . $e->getMessage());
            }
        }

        return ['programs' => $this->fallback($client, $equipments, $freq), 'source' => 'template'];
    }

    /** body_focus selalu berupa array (mendukung data lama berupa teks). */
    private function focusList(array $client): array
    {
        $f = $client['body_focus'] ?? ['Seluruh Tubuh'];
        $f = is_array($f) ? $f : [$f];
        return $f ?: ['Seluruh Tubuh'];
    }

    private function callGemini(array $client, array $equipments, int $freq): ?array
    {
        $focus = $this->focusList($client);

        $system = "Kamu adalah personal trainer profesional yang ramah. Buat program latihan 1 siklus dalam Bahasa Indonesia. "
            . "WAJIB hanya memakai alat dari daftar berikut (atau 'Tanpa Alat' untuk gerakan bodyweight): "
            . implode('; ', $equipments) . '. '
            . "Balas HANYA JSON array berisi tepat {$freq} sesi. Format tiap sesi: "
            . '{"day_label":"Sesi A - Bokong (Glutes)","exercises":[{"name":"...","equipment":"(nama persis dari daftar alat atau Tanpa Alat)","sets_reps":"3 x 12","target_kg":0,"tip":"tips singkat"}]}. '
            . 'Isi MINIMAL 4 dan MAKSIMAL 5 gerakan per sesi (jangan lebih dari 5), urutan dari pemanasan ringan ke gerakan utama. target_kg berupa angka (0 jika bodyweight), konservatif untuk pemula. '
            . 'Klien bisa memiliki BEBERAPA fokus bagian tubuh. Bagi porsi latihan secara seimbang di seluruh sesi mingguan sehingga semua area fokus tertangani '
            . '(contoh: Sesi A fokus Bokong, Sesi B fokus Punggung). Cantumkan area fokus utama tiap sesi pada day_label. '
            . "Jika fokusnya 'Seluruh Tubuh', buat tiap sesi menyeluruh dengan variasi gerakan.";

        $user = sprintf(
            'Klien: %s, gender %s, berat %s kg, tinggi %s cm. Tujuan: %s. Fokus bagian tubuh (%d area): %s. Frekuensi PT: %dx/minggu. Catatan: %s',
            $client['name'], $client['gender'], $client['weight_kg'], $client['height_cm'] ?? '-',
            $client['goal'], count($focus), implode(', ', $focus), $freq, $client['notes'] ?? '-'
        );

        $model = config('services.gemini.model');
        $res = Http::timeout(40)->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.7],
            ]);

        if (! $res->successful()) {
            throw new \RuntimeException('HTTP ' . $res->status());
        }

        $text = $res->json('candidates.0.content.parts.0.text');
        $data = json_decode((string) $text, true);
        if (! is_array($data) || empty($data)) {
            return null;
        }
        if (isset($data['day_label'])) {
            $data = [$data];
        }

        $programs = [];
        foreach (array_slice($data, 0, $freq) as $i => $day) {
            $ex = [];
            foreach (($day['exercises'] ?? []) as $e) {
                if (empty($e['name'])) {
                    continue;
                }
                $ex[] = [
                    'name' => (string) $e['name'],
                    'equipment' => (string) ($e['equipment'] ?? 'Tanpa Alat'),
                    'sets_reps' => (string) ($e['sets_reps'] ?? '3 x 12'),
                    'target_kg' => (float) ($e['target_kg'] ?? 0),
                    'tip' => (string) ($e['tip'] ?? ''),
                ];
            }
            $ex = array_slice($ex, 0, self::MAX_EXERCISES); // jaga-jaga jika AI membuat lebih dari 5
            if ($ex) {
                $programs[] = [
                    'day_label' => (string) ($day['day_label'] ?? 'Sesi ' . chr(65 + $i)),
                    'exercises' => $ex,
                ];
            }
        }
        return $programs ?: null;
    }

    /**
     * Template rapi yang menyesuaikan dengan alat yang tersedia dan membagi
     * beberapa area fokus secara bergiliran ke seluruh sesi mingguan.
     */
    public function fallback(array $client, array $equipments, int $freq): array
    {
        $freq = max(1, $freq);
        $focus = $this->focusList($client);
        $wanita = ($client['gender'] ?? '') === 'Wanita';
        $base = $wanita ? 0.6 : 1.0; // pengali beban awal

        $find = function (string $kw) use ($equipments) {
            foreach ($equipments as $n) {
                if (stripos($n, $kw) !== false) {
                    return $n;
                }
            }
            return null;
        };

        // Bangun satu gerakan. Jika alat tidak ada, pakai gerakan bodyweight pengganti ($alt) atau lewati.
        $ex = function (string $name, ?string $kw, string $sets, float $target, string $tip, ?string $alt = null) use ($find, $base) {
            $eq = $kw ? $find($kw) : null;
            if ($kw && ! $eq) {
                if (! $alt) {
                    return null;
                }
                return ['name' => $alt, 'equipment' => 'Tanpa Alat', 'sets_reps' => $sets, 'target_kg' => 0, 'tip' => $tip];
            }
            return [
                'name' => $name, 'equipment' => $eq ?? 'Tanpa Alat', 'sets_reps' => $sets,
                'target_kg' => $eq ? round($target * $base * 2) / 2 : 0, 'tip' => $tip,
            ];
        };

        $lib = [
            'Bokong (Glutes)' => [
                $ex('Hip Thrust', 'barbell', '3 x 12', 20, 'Remas bokong di puncak gerakan.', 'Glute Bridge'),
                $ex('Romanian Deadlift', 'dumbbell', '3 x 12', 6, 'Punggung lurus, dorong pinggul ke belakang.', 'Good Morning Bodyweight'),
                $ex('Glute Bridge', 'matras', '3 x 15', 0, 'Tahan 2 detik di atas.'),
                $ex('Donkey Kick', 'matras', '3 x 15 /kaki', 0, 'Jaga pinggul tetap rata.'),
            ],
            'Kaki & Paha' => [
                $ex('Goblet Squat', 'dumbbell', '3 x 12', 6, 'Punggung tegak, lutut searah jari kaki.', 'Bodyweight Squat'),
                $ex('Leg Press', 'leg press', '3 x 12', 30, 'Jangan kunci lutut di ujung gerakan.', 'Wall Sit'),
                $ex('Walking Lunges', 'dumbbell', '2 x 10 /kaki', 4, 'Langkah cukup lebar, lutut stabil.', 'Lunges Bodyweight'),
                $ex('Calf Raise', null, '3 x 15', 0, 'Naik perlahan, turun terkontrol.'),
            ],
            'Dada (Chest)' => [
                $ex('Chest Press Machine', 'chest', '3 x 12', 15, 'Hembuskan napas saat mendorong, bahu turun.', 'Push Up Lutut'),
                $ex('Dumbbell Floor Press', 'dumbbell', '3 x 12', 5, 'Siku sekitar 45 derajat dari badan.', 'Push Up Lutut'),
                $ex('Dumbbell Fly', 'dumbbell', '3 x 12', 3, 'Siku sedikit ditekuk, buka dada perlahan.', 'Wide Push Up Lutut'),
                $ex('Push Up', null, '3 x 8', 0, 'Badan lurus, boleh di lutut dulu.'),
            ],
            'Punggung (Back)' => [
                $ex('Lat Pulldown', 'lat', '3 x 12', 20, 'Tarik siku ke arah pinggang.', 'Superman Hold'),
                $ex('Seated Row', 'row', '3 x 12', 15, 'Dada terbuka, bahu turun.', 'Reverse Snow Angel'),
                $ex('Dumbbell Row', 'dumbbell', '3 x 12', 5, 'Punggung rata, tarik dengan siku.', 'Superman'),
                $ex('Superman', 'matras', '3 x 12', 0, 'Angkat dada dan kaki pelan-pelan.'),
            ],
            'Lengan & Bahu' => [
                $ex('Dumbbell Shoulder Press', 'dumbbell', '3 x 12', 4, 'Jaga perut tetap kencang.', 'Pike Push Up'),
                $ex('Lateral Raise', 'dumbbell', '3 x 12', 2, 'Siku sedikit ditekuk, jangan diayun.'),
                $ex('Bicep Curl', 'dumbbell', '3 x 12', 3, 'Siku tetap dekat badan.'),
                $ex('Triceps Extension', 'dumbbell', '3 x 12', 3, 'Siku menghadap ke depan.', 'Triceps Dip Kursi'),
            ],
            'Perut & Pinggang' => [
                $ex('Plank', 'matras', '3 x 30 detik', 0, 'Perut kencang, badan lurus.'),
                $ex('Russian Twist', 'matras', '3 x 20', 0, 'Putar dari pinggang, bukan lengan.'),
                $ex('Dead Bug', 'matras', '3 x 10', 0, 'Pinggang menempel lantai.'),
                $ex('Bicycle Crunch', 'matras', '3 x 20', 0, 'Pelan dan terkontrol.'),
            ],
            'Seluruh Tubuh' => [
                $ex('Squat', 'dumbbell', '3 x 12', 6, 'Turun sampai paha sejajar lantai.', 'Bodyweight Squat'),
                $ex('Chest Press', 'chest', '3 x 12', 15, 'Hembuskan napas saat mendorong.', 'Push Up Lutut'),
                $ex('Seated Row', 'row', '3 x 12', 15, 'Dada terbuka, bahu turun.', 'Superman'),
                $ex('Lunges', 'dumbbell', '2 x 10 /kaki', 4, 'Lutut tidak melewati ujung kaki.', 'Lunges Bodyweight'),
                $ex('Dead Bug', 'matras', '3 x 10', 0, 'Pinggang menempel lantai.'),
            ],
        ];
        $lib = array_map(fn ($list) => array_values(array_filter($list)), $lib);

        $warmup = fn () => $find('treadmill')
            ? $ex('Jalan Cepat / Pemanasan', 'treadmill', '1 x 5 menit', 0, 'Pemanasan santai sampai badan hangat.')
            : $ex('Pemanasan Dinamis', null, '2 x 10', 0, 'Putar bahu, pinggul, dan lutut pelan-pelan.');

        // Bagi area fokus ke tiap sesi secara bergiliran
        $n = count($focus);
        $assign = array_fill(0, $freq, []);
        if ($n >= $freq) {
            foreach ($focus as $i => $f) {
                $assign[$i % $freq][] = $f;
            }
        } else {
            for ($s = 0; $s < $freq; $s++) {
                $assign[$s][] = $focus[$s % $n];
            }
        }

        $programs = [];
        foreach ($assign as $s => $areas) {
            // Total 5 gerakan: 1 pemanasan + 4 gerakan utama dibagi rata ke area fokus sesi ini
            $mainSlots = self::MAX_EXERCISES - 1;
            $areasUsed = array_slice($areas, 0, $mainSlots);
            $base4 = intdiv($mainSlots, count($areasUsed));
            $extra = $mainSlots % count($areasUsed);
            // Variasi gerakan: sesi berikutnya yang memakai area sama mulai dari gerakan berbeda
            $rot = intdiv($s, max(1, $n)) % 2;
            $exercises = [$warmup()];
            foreach ($areasUsed as $i => $a) {
                $list = $lib[$a] ?? $lib['Seluruh Tubuh'];
                if ($rot && count($list) > 1) {
                    $list = array_merge(array_slice($list, 1), array_slice($list, 0, 1));
                }
                $exercises = array_merge($exercises, array_slice($list, 0, $base4 + ($i < $extra ? 1 : 0)));
            }
            $exercises = array_values(array_filter($exercises));
            // Jika ada gerakan yang terlewat (alat tidak ada), lengkapi sampai minimal 4
            foreach (array_merge($lib['Perut & Pinggang'], $lib['Seluruh Tubuh']) as $filler) {
                if (count($exercises) >= self::MIN_EXERCISES) {
                    break;
                }
                if (! in_array($filler['name'], array_column($exercises, 'name'), true)) {
                    $exercises[] = $filler;
                }
            }
            $programs[] = [
                'day_label' => 'Sesi ' . chr(65 + $s) . ' - ' . implode(' & ', $areasUsed),
                'exercises' => array_slice($exercises, 0, self::MAX_EXERCISES),
            ];
        }
        return $programs;
    }
}
