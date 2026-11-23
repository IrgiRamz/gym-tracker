<?php

namespace App\Services;

use App\Models\Equipment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiWorkoutService
{
    /**
     * Jumlah gerakan UTAMA per sesi: minimal 5, atau 6 bila fokusnya hanya
     * "Seluruh Tubuh" dan frekuensinya 1x/minggu (satu-satunya sesi minggu itu harus padat).
     * Pemanasan (jalan cepat, treadmill, sepeda statis, peregangan) BUKAN gerakan:
     * tidak dihitung dan tidak dimasukkan ke daftar gerakan sama sekali.
     */
    private function minExercises(array $client, int $freq): int
    {
        return ($this->focusList($client) === ['Seluruh Tubuh'] && $freq <= 1) ? 6 : 5;
    }

    private function maxExercises(array $client, int $freq): int
    {
        return $this->minExercises($client, $freq) + 1;
    }

    /** Nama gerakan pemanasan yang tidak boleh masuk daftar gerakan (jaga-jaga dari hasil AI). */
    private function isWarmup(string $name): bool
    {
        foreach (['pemanasan', 'jalan cepat', 'treadmill', 'sepeda statis', 'elliptical'] as $kw) {
            if (stripos($name, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

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
                    // Jaminan jumlah minimal: sesi AI yang gerakannya kurang dari minimal
                    // dilengkapi dari template fallback (nama yang belum ada).
                    $fallback = $this->fallback($client, $equipments, $freq);
                    $min = $this->minExercises($client, $freq);
                    foreach ($programs as $i => $day) {
                        $names = array_column($day['exercises'], 'name');
                        foreach (($fallback[$i]['exercises'] ?? []) as $f) {
                            if (count($programs[$i]['exercises']) >= $min) {
                                break;
                            }
                            if (! in_array($f['name'], $names, true)) {
                                $programs[$i]['exercises'][] = $f;
                                $names[] = $f['name'];
                            }
                        }
                    }
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
        $min = $this->minExercises($client, $freq);
        $max = $this->maxExercises($client, $freq);

        $system = "Kamu adalah personal trainer profesional yang ramah. Buat program latihan 1 siklus dalam Bahasa Indonesia. "
            . "WAJIB hanya memakai alat dari daftar berikut (atau 'Tanpa Alat' untuk gerakan bodyweight): "
            . implode('; ', $equipments) . '. '
            . "Balas HANYA JSON array berisi tepat {$freq} sesi. Format tiap sesi: "
            . '{"day_label":"Sesi A - Bokong (Glutes)","exercises":[{"name":"...","equipment":"(nama persis dari daftar alat atau Tanpa Alat)","sets_reps":"3 x 12","target_kg":0,"tip":"tips singkat"}]}. '
            . "Isi MINIMAL {$min} dan MAKSIMAL {$max} gerakan utama per sesi. Pemanasan (jalan cepat, treadmill, sepeda statis, elliptical, peregangan) BUKAN gerakan: jangan masukkan ke daftar exercises sama sekali. Urutkan dari gerakan utama ke gerakan pendukung. target_kg berupa angka (0 jika bodyweight), konservatif untuk pemula. "
            . 'Klien bisa memiliki BEBERAPA fokus bagian tubuh. Bagi porsi latihan secara seimbang di seluruh sesi mingguan sehingga semua area fokus tertangani '
            . '(contoh: Sesi A fokus Bokong, Sesi B fokus Punggung). Cantumkan area fokus utama tiap sesi pada day_label. '
            . "Jika fokusnya 'Seluruh Tubuh', buat tiap sesi menyeluruh dengan variasi gerakan.";

        $user = sprintf(
            'Klien: %s, gender %s, berat %s kg, tinggi %s cm. Tujuan: %s. Fokus bagian tubuh (%d area): %s. Frekuensi PT: %dx/minggu. Catatan: %s',
            $client['name'], $client['gender'], $client['weight_kg'], $client['height_cm'] ?? '-',
            $client['goal'] . (filled($client['goal_note']) ? ' — ' . $client['goal_note'] : ''),
            count($focus), implode(', ', $focus), $freq, $client['notes'] ?? '-'
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
                if (empty($e['name']) || $this->isWarmup((string) $e['name'])) {
                    continue; // nama kosong atau gerakan pemanasan tidak dihitung sebagai gerakan
                }
                $ex[] = [
                    'name' => (string) $e['name'],
                    'equipment' => (string) ($e['equipment'] ?? 'Tanpa Alat'),
                    'sets_reps' => (string) ($e['sets_reps'] ?? '3 x 12'),
                    'target_kg' => (float) ($e['target_kg'] ?? 0),
                    'tip' => (string) ($e['tip'] ?? ''),
                ];
            }
            $ex = array_slice($ex, 0, $this->maxExercises($client, $freq)); // jaga-jaga jika AI membuat lebih dari maksimal
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

        // Cari alat pertama yang namanya memuat kata kunci. $kw bisa daftar kata kunci (dicoba berurutan).
        $find = function (string|array $kw) use ($equipments) {
            foreach ((array) $kw as $k) {
                foreach ($equipments as $n) {
                    if (stripos($n, $k) !== false) {
                        return $n;
                    }
                }
            }
            return null;
        };

        // Bangun satu gerakan. Jika alat tidak ada, pakai gerakan bodyweight pengganti ($alt) atau lewati.
        // $kw null = gerakan bodyweight (tanpa alat).
        $ex = function (string $name, string|array|null $kw, string $sets, float $target, string $tip, ?string $alt = null) use ($find, $base) {
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
            // Urutan = prioritas. Gerakan tanpa alat (kw null) adalah bodyweight di area gym.
            'Bokong (Glutes)' => [
                $ex('Hip Thrust', ['hip thrust', 'barbell'], '3 x 12', 20, 'Remas bokong di puncak gerakan.', 'Glute Bridge'),
                $ex('Romanian Deadlift', 'dumbbell', '3 x 12', 6, 'Punggung lurus, dorong pinggul ke belakang.', 'Good Morning Bodyweight'),
                $ex('Glute Kickback Machine', 'glute kickback', '3 x 12 /kaki', 10, 'Dorong dengan tumit, jangan melengkungkan punggung.', 'Donkey Kick'),
                $ex('Hip Abduction Machine', 'hip abduction', '3 x 15', 20, 'Buka perlahan, tahan sebentar di ujung gerakan.'),
                $ex('Glute Bridge', null, '3 x 15', 0, 'Tahan 2 detik di atas.'),
                $ex('Donkey Kick', null, '3 x 15 /kaki', 0, 'Jaga pinggul tetap rata.'),
            ],
            'Kaki & Paha' => [
                $ex('Goblet Squat', 'dumbbell', '3 x 12', 6, 'Punggung tegak, lutut searah jari kaki.', 'Bodyweight Squat'),
                $ex('Leg Press', 'leg press', '3 x 12', 30, 'Jangan kunci lutut di ujung gerakan.', 'Wall Sit'),
                $ex('Leg Extension', 'leg extension', '3 x 12', 15, 'Tahan 1 detik di atas, turun perlahan.'),
                $ex('Leg Curl', 'leg curl', '3 x 12', 15, 'Tarik tumit ke arah bokong, gerakan terkontrol.'),
                $ex('Walking Lunges', 'dumbbell', '2 x 10 /kaki', 4, 'Langkah cukup lebar, lutut stabil.', 'Lunges Bodyweight'),
                $ex('Hack Squat', 'hack squat', '3 x 10', 20, 'Punggung menempel sandaran, turun sampai nyaman.'),
                $ex('Smith Machine Squat', 'smith', '3 x 10', 15, 'Kaki sedikit di depan, dada tegak.'),
                $ex('Calf Raise', ['calf raise'], '3 x 15', 20, 'Naik perlahan, turun terkontrol.', 'Calf Raise Bodyweight'),
            ],
            'Dada (Chest)' => [
                $ex('Chest Press Machine', 'chest press', '3 x 12', 15, 'Hembuskan napas saat mendorong, bahu turun.', 'Push Up Lutut'),
                $ex('Dumbbell Bench Press', 'bench', '3 x 12', 5, 'Siku sekitar 45 derajat dari badan.', 'Push Up Lutut'),
                $ex('Pec Deck', 'pec deck', '3 x 12', 15, 'Peras dada di tengah, jangan angkat bahu.'),
                $ex('Incline Dumbbell Press', 'incline', '3 x 10', 5, 'Dorong ke atas dada, siku tidak terlalu lebar.'),
                $ex('Cable Fly', 'cable', '3 x 12', 5, 'Siku sedikit ditekuk, peluk ke depan dada.'),
                $ex('Push Up', null, '3 x 8', 0, 'Badan lurus, boleh di lutut dulu.'),
            ],
            'Punggung (Back)' => [
                $ex('Lat Pulldown', 'lat pulldown', '3 x 12', 20, 'Tarik siku ke arah pinggang.', 'Superman Hold'),
                $ex('Seated Row', 'seated row', '3 x 12', 15, 'Dada terbuka, bahu turun.', 'Reverse Snow Angel'),
                $ex('Dumbbell Row', 'dumbbell', '3 x 12', 5, 'Punggung rata, tarik dengan siku.', 'Superman'),
                $ex('Assisted Pull-Up', 'assisted', '3 x 8', 20, 'Tarik dagu melewati bar, turun perlahan.'),
                $ex('Back Extension', 'back extension', '3 x 12', 0, 'Naik sampai badan lurus, jangan berlebihan melengkung.', 'Superman'),
                $ex('Superman', null, '3 x 12', 0, 'Angkat dada dan kaki pelan-pelan.'),
            ],
            'Lengan & Bahu' => [
                $ex('Dumbbell Shoulder Press', 'dumbbell', '3 x 12', 4, 'Jaga perut tetap kencang.', 'Pike Push Up'),
                $ex('Lateral Raise', 'dumbbell', '3 x 12', 2, 'Siku sedikit ditekuk, jangan diayun.'),
                $ex('Bicep Curl', ['dumbbell', 'ez bar'], '3 x 12', 3, 'Siku tetap dekat badan.'),
                $ex('Triceps Pushdown', 'cable', '3 x 12', 10, 'Siku menempel badan, tekan sampai lurus.'),
                $ex('Shoulder Press Machine', 'shoulder press', '3 x 12', 10, 'Jangan kunci siku di atas.'),
                $ex('Triceps Extension', 'dumbbell', '3 x 12', 3, 'Siku menghadap ke depan.', 'Triceps Dip Kursi'),
            ],
            'Perut & Pinggang' => [
                $ex('Plank', null, '3 x 30 detik', 0, 'Perut kencang, badan lurus.'),
                $ex('Cable Crunch', 'cable', '3 x 15', 10, 'Tekuk dari perut, bukan dari tarikan lengan.'),
                $ex('Russian Twist', null, '3 x 20', 0, 'Putar dari pinggang, bukan lengan.'),
                $ex('Ab Crunch Machine', 'ab crunch', '3 x 15', 15, 'Hembuskan napas saat menekuk badan.'),
                $ex('Dead Bug', null, '3 x 10', 0, 'Pinggang menempel lantai.'),
                $ex('Bicycle Crunch', null, '3 x 20', 0, 'Pelan dan terkontrol.'),
            ],
            'Seluruh Tubuh' => [
                $ex('Squat', 'dumbbell', '3 x 12', 6, 'Turun sampai paha sejajar lantai.', 'Bodyweight Squat'),
                $ex('Chest Press', 'chest press', '3 x 12', 15, 'Hembuskan napas saat mendorong.', 'Push Up Lutut'),
                $ex('Seated Row', 'seated row', '3 x 12', 15, 'Dada terbuka, bahu turun.', 'Superman'),
                $ex('Lunges', 'dumbbell', '2 x 10 /kaki', 4, 'Lutut tidak melewati ujung kaki.', 'Lunges Bodyweight'),
                $ex('Plank', null, '3 x 30 detik', 0, 'Perut kencang, badan lurus.'),
                $ex('Dead Bug', null, '3 x 10', 0, 'Pinggang menempel lantai.'),
            ],
        ];
        // Buang gerakan yang dilewati (alat tidak ada) dan gerakan kembar (mis. pengganti bodyweight yang sama)
        $lib = array_map(function ($list) {
            $seen = [];
            $out = [];
            foreach (array_filter($list) as $e) {
                if (! isset($seen[$e['name']])) {
                    $seen[$e['name']] = true;
                    $out[] = $e;
                }
            }
            return $out;
        }, $lib);

        // Catatan: pemanasan SENGAJA tidak dimasukkan ke daftar gerakan (bukan gerakan, tidak dihitung).

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

        $min = $this->minExercises($client, $freq);
        $max = $this->maxExercises($client, $freq);
        $programs = [];
        foreach ($assign as $s => $areas) {
            // Semua slot adalah gerakan utama (tanpa pemanasan), dibagi rata ke area fokus sesi ini
            $mainSlots = $min;
            $areasUsed = array_slice($areas, 0, $mainSlots);
            $baseN = intdiv($mainSlots, count($areasUsed));
            $extra = $mainSlots % count($areasUsed);
            // Variasi gerakan: sesi berikutnya yang memakai area sama mulai dari gerakan berbeda
            $rot = intdiv($s, max(1, $n)) % 2;
            $exercises = [];
            foreach ($areasUsed as $i => $a) {
                $list = $lib[$a] ?? $lib['Seluruh Tubuh'];
                if ($rot && count($list) > 1) {
                    $list = array_merge(array_slice($list, 1), array_slice($list, 0, 1));
                }
                $exercises = array_merge($exercises, array_slice($list, 0, $baseN + ($i < $extra ? 1 : 0)));
            }
            $exercises = array_values(array_filter($exercises));
            // Jika ada gerakan yang terlewat (alat tidak ada), lengkapi sampai jumlah minimal
            foreach (array_merge($lib['Perut & Pinggang'], $lib['Seluruh Tubuh']) as $filler) {
                if (count($exercises) >= $min) {
                    break;
                }
                if (! in_array($filler['name'], array_column($exercises, 'name'), true)) {
                    $exercises[] = $filler;
                }
            }
            $programs[] = [
                'day_label' => 'Sesi ' . chr(65 + $s) . ' - ' . implode(' & ', $areasUsed),
                'exercises' => array_slice($exercises, 0, $max),
            ];
        }
        return $programs;
    }
}
