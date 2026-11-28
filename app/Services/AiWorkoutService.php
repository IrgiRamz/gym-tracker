<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\ExerciseMedia;
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

    /** Nama gerakan bodyweight murni: tidak boleh masuk program walau AI menandainya beralat (mis. di atas matras). */
    private function isBodyweightName(string $name): bool
    {
        foreach (['plank', 'push up', 'push-up', 'pushup', 'superman', 'burpee', 'mountain climber', 'jumping jack', 'sit up', 'sit-up', 'dead bug'] as $kw) {
            if (stripos($name, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pengecualian aturan wajib beralat: gerakan berbasis WAKTU (durasi detik, tanpa
     * beban) yang diminta Irgi satu per satu: Plank (2026-11-27) dan Wall Sit (2026-11-28).
     * Hanya ditawarkan sebagai pilihan MANUAL pelatih (Tambah Gerakan / Ganti Gerakan);
     * program otomatis (AI maupun template) tetap beralat semua.
     */
    private function timedEntries(): array
    {
        return [
            'plank' => [
                'name' => 'Plank', 'equipment' => 'Tanpa Alat', 'sets_reps' => '3 x 45 detik',
                'target_kg' => 0, 'timed' => true,
                'tip' => 'Badan lurus dari kepala sampai tumit, kencangkan perut dan bokong, jangan sampai pinggul turun.',
            ],
            'wall sit' => [
                'name' => 'Wall Sit', 'equipment' => 'Tanpa Alat', 'sets_reps' => '3 x 45 detik',
                'target_kg' => 0, 'timed' => true,
                'tip' => 'Punggung menempel penuh ke dinding, lutut menekuk 90 derajat seperti duduk di kursi, tahan posisinya.',
            ],
        ];
    }

    /** Gerakan berbasis waktu yang ditawarkan untuk satu area (pilihan manual pelatih). */
    private function timedForArea(string $area): array
    {
        $keys = match ($area) {
            'Perut & Pinggang' => ['plank', 'wall sit'],
            'Kaki & Paha' => ['wall sit'],
            default => [],
        };
        $entries = $this->timedEntries();
        return array_values(array_map(fn ($k) => $entries[$k], $keys));
    }

    /** Bentuk resmi gerakan berbasis waktu dari masukan luar; null bila namanya bukan salah satunya. */
    private function asTimedExercise(array $e): ?array
    {
        $entries = $this->timedEntries();
        $key = ExerciseMedia::keyFor((string) ($e['name'] ?? ''));
        if (! isset($entries[$key])) {
            return null;
        }
        $entry = $entries[$key];
        $setsReps = trim((string) ($e['sets_reps'] ?? ''));
        if ($setsReps !== '') {
            $entry['sets_reps'] = $setsReps;
        }
        if (! empty($e['tip'])) {
            $entry['tip'] = (string) $e['tip'];
        }
        return $entry;
    }

    /**
     * Nama alat resmi dari daftar tersedia bila gerakan AI ini memakai alat itu;
     * null bila gerakannya tanpa alat atau alatnya tidak ada di daftar.
     * Program WAJIB hanya berisi gerakan beralat (keputusan Irgi 2026-11-23:
     * ke gym ya memakai alat gym, bukan gerakan bodyweight).
     */
    private function matchedEquipment(array $e, array $equipments): ?string
    {
        if ($this->isBodyweightName((string) ($e['name'] ?? ''))) {
            return null;
        }
        $given = strtolower(trim((string) ($e['equipment'] ?? '')));
        if ($given === '' || $given === 'tanpa alat' || $given === 'bodyweight') {
            return null;
        }
        foreach ($equipments as $eq) {
            $eqL = strtolower($eq);
            if ($given === $eqL || stripos($eqL, $given) !== false || stripos($given, $eqL) !== false) {
                return $eq;
            }
        }
        return null;
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
            . "WAJIB: setiap gerakan HARUS memakai satu alat dari daftar berikut, tanpa kecuali: "
            . implode('; ', $equipments) . '. '
            . "Balas HANYA JSON array berisi tepat {$freq} sesi. Format tiap sesi: "
            . '{"day_label":"Sesi A - Bokong (Glutes)","exercises":[{"name":"...","equipment":"(nama persis dari daftar alat)","sets_reps":"3 x 12","target_kg":0,"tip":"tips singkat"}]}. '
            . "Isi MINIMAL {$min} dan MAKSIMAL {$max} gerakan utama per sesi. Pemanasan (jalan cepat, treadmill, sepeda statis, elliptical, peregangan) BUKAN gerakan: jangan masukkan ke daftar exercises sama sekali. Urutkan dari gerakan utama ke gerakan pendukung. target_kg berupa angka perkiraan beban alat, konservatif untuk pemula. "
            . "Gerakan bodyweight/tanpa alat (push up, plank, superman, burpee, sit up, dan sejenisnya) DILARANG KERAS: klien datang ke gym untuk memakai alat gym. "
            . 'Klien bisa memiliki BEBERAPA fokus bagian tubuh. Bagi porsi latihan secara seimbang di seluruh sesi mingguan sehingga semua area fokus tertangani '
            . '(contoh: Sesi A fokus Bokong, Sesi B fokus Punggung). Cantumkan area fokus utama tiap sesi pada day_label. '
            . "Jika fokusnya 'Seluruh Tubuh', buat tiap sesi menyeluruh dengan variasi gerakan.";

        $user = sprintf(
            'Klien: %s, gender %s, umur %s tahun, berat %s kg, tinggi %s cm. Tujuan: %s. Fokus bagian tubuh (%d area): %s. Frekuensi PT: %dx/minggu. Catatan: %s',
            $client['name'], $client['gender'], $client['age'] ?? '-', $client['weight_kg'], $client['height_cm'] ?? '-',
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
                $eq = $this->matchedEquipment($e, $equipments);
                if ($eq === null) {
                    continue; // gerakan tanpa alat / alatnya tidak ada di daftar: program wajib beralat semua
                }
                $ex[] = [
                    'name' => (string) $e['name'],
                    'equipment' => $eq,
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
     * Pustaka gerakan per area fokus: hanya gerakan beralat yang alatnya tersedia,
     * gerakan kembar dalam satu area dibuang. Dipakai template fallback dan fitur Ganti Gerakan.
     */
    private function buildLibrary(array $equipments, bool $wanita): array
    {
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

        // Bangun satu gerakan. Gerakan WAJIB beralat: alatnya tidak ditemukan -> gerakan dilewati (null).
        // Tidak ada lagi gerakan bodyweight atau pengganti bodyweight (keputusan Irgi 2026-11-23).
        $ex = function (string $name, string|array|null $kw, string $sets, float $target, string $tip) use ($find, $base) {
            $eq = $kw ? $find($kw) : null;
            if (! $eq) {
                return null;
            }
            return [
                'name' => $name, 'equipment' => $eq, 'sets_reps' => $sets,
                'target_kg' => round($target * $base * 2) / 2, 'tip' => $tip,
            ];
        };

        $lib = [
            // Urutan = prioritas. Semua gerakan memakai alat dari daftar alat yang tersedia.
            'Bokong (Glutes)' => [
                $ex('Hip Thrust', ['hip thrust', 'barbell'], '3 x 12', 20, 'Remas bokong di puncak gerakan.'),
                $ex('Romanian Deadlift', 'dumbbell', '3 x 12', 6, 'Punggung lurus, dorong pinggul ke belakang.'),
                $ex('Cable Kickback', 'cable', '3 x 12 /kaki', 5, 'Pasang tali pergelangan kaki, dorong kaki ke belakang dengan tumit.'),
                $ex('Smith Machine Squat', 'smith', '3 x 10', 15, 'Kaki sedikit di depan, dada tegak.'),
                $ex('Leg Press', 'leg press', '3 x 12', 30, 'Kaki agak tinggi di pijakan untuk menekan bokong.'),
                $ex('Leg Curl', 'leg curl', '3 x 12', 15, 'Tarik tumit ke arah bokong, gerakan terkontrol.'),
            ],
            'Kaki & Paha' => [
                $ex('Goblet Squat', 'dumbbell', '3 x 12', 6, 'Punggung tegak, lutut searah jari kaki.'),
                $ex('Leg Press', 'leg press', '3 x 12', 30, 'Jangan kunci lutut di ujung gerakan.'),
                $ex('Leg Extension', ['leg extension', 'extension'], '3 x 12', 15, 'Tahan 1 detik di atas, turun perlahan.'),
                $ex('Leg Curl', 'leg curl', '3 x 12', 15, 'Tarik tumit ke arah bokong, gerakan terkontrol.'),
                $ex('Walking Lunges', 'dumbbell', '2 x 10 /kaki', 4, 'Langkah cukup lebar, lutut stabil.'),
                $ex('Hack Squat', 'hack squat', '3 x 10', 20, 'Punggung menempel sandaran, turun sampai nyaman.'),
                $ex('Smith Machine Squat', 'smith', '3 x 10', 15, 'Kaki sedikit di depan, dada tegak.'),
                $ex('Calf Raise', ['leg press', 'smith'], '3 x 15', 20, 'Naik perlahan dengan ujung kaki, turun terkontrol.'),
            ],
            'Dada (Chest)' => [
                $ex('Chest Press Machine', ['chest press', 'bench press'], '3 x 12', 15, 'Hembuskan napas saat mendorong, bahu turun.'),
                $ex('Dumbbell Bench Press', 'bench', '3 x 12', 5, 'Siku sekitar 45 derajat dari badan.'),
                $ex('Pec Deck', 'pec deck', '3 x 12', 15, 'Peras dada di tengah, jangan angkat bahu.'),
                $ex('Incline Dumbbell Press', ['adjustable bench', 'bench'], '3 x 10', 5, 'Atur sandaran miring ke atas, dorong ke atas dada.'),
                $ex('Cable Fly', 'cable', '3 x 12', 5, 'Siku sedikit ditekuk, peluk ke depan dada.'),
            ],
            'Punggung (Back)' => [
                $ex('Lat Pulldown', 'lat pulldown', '3 x 12', 20, 'Tarik siku ke arah pinggang.'),
                $ex('Seated Row', 'seated row', '3 x 12', 15, 'Dada terbuka, bahu turun.'),
                $ex('Dumbbell Row', 'dumbbell', '3 x 12', 5, 'Punggung rata, tarik dengan siku.'),
                $ex('Deadlift', 'deadlift', '3 x 10', 20, 'Punggung lurus, dorong lantai dengan kaki.'),
                $ex('Straight Arm Pulldown', 'cable', '3 x 12', 10, 'Lengan hampir lurus, tarik dari punggung samping.'),
            ],
            'Lengan & Bahu' => [
                $ex('Dumbbell Shoulder Press', 'dumbbell', '3 x 12', 4, 'Jaga perut tetap kencang.'),
                $ex('Lateral Raise', 'dumbbell', '3 x 12', 2, 'Siku sedikit ditekuk, jangan diayun.'),
                $ex('Bicep Curl', ['dumbbell', 'ez bar'], '3 x 12', 3, 'Siku tetap dekat badan.'),
                $ex('Triceps Pushdown', 'cable', '3 x 12', 10, 'Siku menempel badan, tekan sampai lurus.'),
                $ex('Shoulder Press Machine', 'shoulder press', '3 x 12', 10, 'Jangan kunci siku di atas.'),
                $ex('Triceps Extension', 'dumbbell', '3 x 12', 3, 'Siku menghadap ke depan.'),
            ],
            'Perut & Pinggang' => [
                $ex('Cable Crunch', 'cable', '3 x 15', 10, 'Tekuk dari perut, bukan dari tarikan lengan.'),
                $ex('Russian Twist', 'dumbbell', '3 x 20', 3, 'Pegang satu dumbbell di depan dada, putar dari pinggang.'),
                $ex('Cable Woodchopper', 'cable', '3 x 12 /sisi', 5, 'Putar badan menyamping dengan perut, lengan mengikuti.'),
            ],
            'Seluruh Tubuh' => [
                $ex('Squat', 'dumbbell', '3 x 12', 6, 'Turun sampai paha sejajar lantai.'),
                $ex('Chest Press', ['chest press', 'bench press'], '3 x 12', 15, 'Hembuskan napas saat mendorong.'),
                $ex('Seated Row', 'seated row', '3 x 12', 15, 'Dada terbuka, bahu turun.'),
                $ex('Lunges', 'dumbbell', '2 x 10 /kaki', 4, 'Lutut tidak melewati ujung kaki.'),
                $ex('Leg Press', 'leg press', '3 x 12', 30, 'Jangan kunci lutut di ujung gerakan.'),
                $ex('Lat Pulldown', 'lat pulldown', '3 x 12', 20, 'Tarik siku ke arah pinggang.'),
            ],
        ];
        // Buang gerakan yang dilewati (alat tidak ada) dan gerakan kembar dalam satu area
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

        return $lib;
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

        $lib = $this->buildLibrary($equipments, $wanita);

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
            foreach (array_merge($lib['Seluruh Tubuh'], $lib['Perut & Pinggang']) as $filler) {
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

    /**
     * Area otot sebuah gerakan, ditebak dari NAMA gerakannya (bukan dari daftar asal):
     * Squat selalu kaki walau muncul di sesi Seluruh Tubuh, Chest Press selalu dada.
     * Urutan aturan penting: yang spesifik didahulukan (romanian deadlift -> bokong
     * sebelum deadlift -> punggung; leg curl -> kaki sebelum curl -> lengan).
     * Cadangan terakhir: nama persis di pustaka, lalu label sesi, lalu Seluruh Tubuh.
     */
    private function muscleAreaFor(string $name, string $dayLabel, array $lib): string
    {
        $n = ExerciseMedia::keyFor($name);
        $rules = [
            'Bokong (Glutes)' => ['hip thrust', 'glute', 'kickback', 'abduction', 'romanian deadlift', 'donkey kick'],
            'Kaki & Paha' => ['leg curl', 'leg extension', 'leg press', 'squat', 'lunge', 'calf', 'step up', 'hack', 'wall sit'],
            'Dada (Chest)' => ['chest press', 'bench press', 'pec', 'fly', 'dip'],
            'Punggung (Back)' => ['pulldown', 'pull up', 'pullup', 'row', 'deadlift', 'pullover', 'back extension'],
            'Perut & Pinggang' => ['crunch', 'twist', 'woodchopper', 'leg raise', 'sit up', 'plank'],
            'Lengan & Bahu' => ['shoulder press', 'lateral raise', 'bicep', 'tricep', 'curl', 'front raise', 'rear delt'],
        ];
        foreach ($rules as $area => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($n, $kw)) {
                    return $area;
                }
            }
        }
        foreach ($lib as $a => $items) {
            foreach ($items as $it) {
                if (ExerciseMedia::keyFor($it['name']) === $n) {
                    return $a;
                }
            }
        }
        foreach (array_keys($lib) as $a) {
            if ($a !== 'Seluruh Tubuh' && stripos($dayLabel, $a) !== false) {
                return $a;
            }
        }
        return 'Seluruh Tubuh';
    }

    /**
     * Gerakan alternatif untuk mengganti SATU gerakan dalam program (fitur Ganti Gerakan):
     * gerakan lain dari area otot yang sama (dari nama gerakannya), hanya yang beralat
     * dan alatnya tersedia, dan belum dipakai di sesi yang sama.
     */
    public function alternativesFor(Client $client, string $dayLabel, array $exercises, int $index): array
    {
        if (! isset($exercises[$index])) {
            return [];
        }
        $equipments = Equipment::where('is_available', true)->pluck('name')->all();
        $lib = $this->buildLibrary($equipments, $client->gender === 'Wanita');
        $area = $this->muscleAreaFor((string) ($exercises[$index]['name'] ?? ''), $dayLabel, $lib);

        $used = array_map(fn ($x) => ExerciseMedia::keyFor((string) ($x['name'] ?? '')), $exercises);
        $out = array_values(array_filter(
            $lib[$area] ?? [],
            fn ($it) => ! in_array(ExerciseMedia::keyFor($it['name']), $used, true)
        ));
        // Ganti ke gerakan berbasis waktu juga ditawarkan (pilihan manual pelatih).
        foreach ($this->timedForArea($area) as $entry) {
            if (! in_array(ExerciseMedia::keyFor($entry['name']), $used, true)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Buat SATU gerakan pengganti BARU memakai AI, khusus untuk satu posisi gerakan:
     * otot yang sama dengan gerakan yang diganti, memakai alat yang tersedia, dan
     * berbeda dari semua gerakan di sesi + nama-nama yang dikecualikan (yang sudah
     * ditawarkan). Null bila AI tidak tersedia atau hasilnya tidak lolos saringan.
     */
    public function generateReplacement(Client $client, string $dayLabel, array $exercises, int $index, array $excludeNames = []): ?array
    {
        if (! isset($exercises[$index]) || ! config('services.gemini.key')) {
            return null;
        }
        $equipments = Equipment::where('is_available', true)->pluck('name')->all();
        if (! $equipments) {
            return null;
        }
        $lib = $this->buildLibrary($equipments, $client->gender === 'Wanita');
        $current = $exercises[$index];
        $area = $this->muscleAreaFor((string) ($current['name'] ?? ''), $dayLabel, $lib);
        $avoid = array_values(array_unique(array_merge(
            array_map(fn ($x) => (string) ($x['name'] ?? ''), $exercises),
            $excludeNames
        )));

        $system = 'Kamu adalah personal trainer profesional. Buat TEPAT SATU gerakan latihan pengganti dalam Bahasa Indonesia. '
            . 'WAJIB memakai satu alat dari daftar berikut: ' . implode('; ', $equipments) . '. '
            . 'Balas HANYA JSON object: {"name":"...","equipment":"(nama persis dari daftar alat)","sets_reps":"3 x 12","target_kg":0,"tip":"tips singkat"}. '
            . 'Gerakan bodyweight/tanpa alat DILARANG. Pemanasan BUKAN gerakan. target_kg berupa angka perkiraan beban alat, konservatif sesuai profil klien.';
        $user = sprintf(
            'Klien: %s, gender %s, umur %s tahun, berat %s kg. Gerakan yang diganti: %s (alat: %s). Buat gerakan LAIN yang melatih otot yang sama (area: %s); harus gerakan yang benar-benar berbeda, bukan gerakan yang sama dengan nama lain. Jangan pakai gerakan ini: %s.',
            $client->name, $client->gender, $client->age ?? '-', $client->weight_kg,
            $current['name'] ?? '-', $current['equipment'] ?? '-', $area, implode(', ', $avoid)
        );

        try {
            $model = config('services.gemini.model');
            $res = Http::timeout(40)->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.9],
                ]);
            if (! $res->successful()) {
                throw new \RuntimeException('HTTP ' . $res->status());
            }
            $e = json_decode((string) $res->json('candidates.0.content.parts.0.text'), true);
            if (! is_array($e)) {
                return null;
            }
            $valid = $this->validateReplacement($e);
            if (! $valid) {
                return null;
            }
            $newKey = ExerciseMedia::keyFor($valid['name']);
            foreach ($avoid as $a) {
                if (ExerciseMedia::keyFor($a) === $newKey) {
                    return null; // dobel dengan gerakan di sesi / yang sudah ditawarkan
                }
            }
            return $valid;
        } catch (Throwable $e) {
            Log::warning('Gemini gagal membuat gerakan pengganti: ' . $e->getMessage());
            return null;
        }
    }

    /** Daftar area otot yang dikenal pustaka (untuk pilihan Tambah Gerakan). */
    public function areas(): array
    {
        return ['Bokong (Glutes)', 'Kaki & Paha', 'Dada (Chest)', 'Punggung (Back)', 'Lengan & Bahu', 'Perut & Pinggang', 'Seluruh Tubuh'];
    }

    /**
     * Alternatif dari satu AREA otot tertentu (fitur Tambah Gerakan): gerakan pustaka
     * untuk area itu, hanya yang beralat dan alatnya tersedia, dikurangi nama-nama
     * yang dikecualikan (mis. gerakan yang sudah ada di sesi).
     */
    public function alternativesForArea(Client $client, string $area, array $excludeNames = []): array
    {
        $equipments = Equipment::where('is_available', true)->pluck('name')->all();
        $lib = $this->buildLibrary($equipments, $client->gender === 'Wanita');
        if (! isset($lib[$area])) {
            return [];
        }
        $excl = array_map(fn ($n) => ExerciseMedia::keyFor((string) $n), $excludeNames);
        $out = array_values(array_filter(
            $lib[$area],
            fn ($it) => ! in_array(ExerciseMedia::keyFor($it['name']), $excl, true)
        ));
        // Pilihan manual berbasis waktu untuk area ini (pengecualian beralat, mis. Plank & Wall Sit).
        foreach ($this->timedForArea($area) as $entry) {
            if (! in_array(ExerciseMedia::keyFor($entry['name']), $excl, true)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * Alternatif untuk mengganti gerakan berdasarkan NAMANYA saja (untuk gerakan
     * tambahan khusus sesi yang tidak tercatat di program): area dibaca dari nama
     * gerakan. Mengembalikan [area, daftar alternatif].
     */
    public function alternativesForName(Client $client, string $name, array $excludeNames = []): array
    {
        $equipments = Equipment::where('is_available', true)->pluck('name')->all();
        $lib = $this->buildLibrary($equipments, $client->gender === 'Wanita');
        $area = $this->muscleAreaFor($name, '', $lib);
        $excludeNames[] = $name;
        return [$area, $this->alternativesForArea($client, $area, $excludeNames)];
    }

    /**
     * Buat SATU gerakan BARU memakai AI untuk satu AREA otot tertentu (fitur Tambah
     * Gerakan): wajib beralat dari daftar, beda dari nama-nama yang dikecualikan.
     * Null bila AI tidak tersedia atau hasilnya tidak lolos saringan.
     */
    public function generateForArea(Client $client, string $area, array $excludeNames = []): ?array
    {
        if (! config('services.gemini.key')) {
            return null;
        }
        $equipments = Equipment::where('is_available', true)->pluck('name')->all();
        if (! $equipments) {
            return null;
        }
        $avoid = array_values(array_unique($excludeNames));
        $system = 'Kamu adalah personal trainer profesional. Buat TEPAT SATU gerakan latihan dalam Bahasa Indonesia. '
            . 'WAJIB memakai satu alat dari daftar berikut: ' . implode('; ', $equipments) . '. '
            . 'Balas HANYA JSON object: {"name":"...","equipment":"(nama persis dari daftar alat)","sets_reps":"3 x 12","target_kg":0,"tip":"tips singkat"}. '
            . 'Gerakan bodyweight/tanpa alat DILARANG. Pemanasan BUKAN gerakan. target_kg berupa angka perkiraan beban alat, konservatif sesuai profil klien.';
        $user = sprintf(
            'Klien: %s, gender %s, umur %s tahun, berat %s kg. Buat SATU gerakan latihan yang melatih area otot: %s. Jangan pakai gerakan ini: %s.',
            $client->name, $client->gender, $client->age ?? '-', $client->weight_kg,
            $area, $avoid ? implode(', ', $avoid) : '-'
        );

        try {
            $model = config('services.gemini.model');
            $res = Http::timeout(40)->withHeaders(['x-goog-api-key' => config('services.gemini.key')])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.9],
                ]);
            if (! $res->successful()) {
                throw new \RuntimeException('HTTP ' . $res->status());
            }
            $e = json_decode((string) $res->json('candidates.0.content.parts.0.text'), true);
            if (! is_array($e)) {
                return null;
            }
            $valid = $this->validateReplacement($e);
            if (! $valid) {
                return null;
            }
            $newKey = ExerciseMedia::keyFor($valid['name']);
            foreach ($avoid as $a) {
                if (ExerciseMedia::keyFor($a) === $newKey) {
                    return null;
                }
            }
            return $valid;
        } catch (Throwable $e) {
            Log::warning('Gemini gagal membuat gerakan untuk area: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validasi gerakan pengganti dari luar (pilihan pustaka tidak lewat sini; ini untuk
     * hasil generate AI yang dipilih pelatih): wajib beralat, alatnya ada di daftar,
     * bukan pemanasan/bodyweight. Mengembalikan gerakan dengan nama alat resmi.
     */
    public function validateReplacement(array $e): ?array
    {
        if (empty($e['name']) || $this->isWarmup((string) $e['name'])) {
            return null;
        }
        // Pengecualian berbasis waktu: Plank boleh disimpan walau tanpa alat.
        $timed = $this->asTimedExercise($e);
        if ($timed) {
            return $timed;
        }
        $equipments = Equipment::where('is_available', true)->pluck('name')->all();
        $eq = $this->matchedEquipment($e, $equipments);
        if ($eq === null) {
            return null;
        }
        return [
            'name' => (string) $e['name'],
            'equipment' => $eq,
            'sets_reps' => (string) ($e['sets_reps'] ?? '3 x 12'),
            'target_kg' => (float) ($e['target_kg'] ?? 0),
            'tip' => (string) ($e['tip'] ?? ''),
        ];
    }
}
