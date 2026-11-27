<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\ExerciseMedia;
use App\Models\WorkoutProgram;
use App\Services\AiWorkoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index()
    {
        $clients = Client::with('activePrograms', 'weightLogs')->orderBy('name')->get();
        return view('clients', [
            'clients' => $clients,
            'goals' => Client::GOALS,
            'focuses' => Client::FOCUSES,
            'activities' => array_keys(Client::ACTIVITIES),
            'equipmentCount' => Equipment::where('is_available', true)->count(),
            'equipmentImages' => Equipment::imageMap(),
            'exerciseMedia' => ExerciseMedia::mediaMap(),
        ]);
    }

    /** Aturan validasi yang sama untuk tambah & edit klien. */
    private function validateClient(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\s-]{9,18}$/'],
            'gender' => ['required', Rule::in(['Wanita', 'Pria'])],
            'age' => ['required', 'integer', 'min:5', 'max:100'],
            'weight_kg' => ['required', 'numeric', 'min:20', 'max:300'],
            'height_cm' => ['nullable', 'integer', 'min:100', 'max:230'],
            'waist_cm' => ['nullable', 'integer', 'min:40', 'max:250'],
            'goal' => ['required', Rule::in(Client::GOALS)],
            'goal_note' => ['nullable', 'string', 'max:200'],
            'body_focus' => ['required', 'array', 'min:1'],
            'body_focus.*' => [Rule::in(Client::FOCUSES)],
            'pt_frequency_per_week' => ['required', Rule::in([1, 2, 3, 4, 5])],
            'activity_level' => ['required', Rule::in(array_keys(Client::ACTIVITIES))],
            'remaining_sessions' => ['required', 'integer', 'min:0', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'name.required' => 'Nama klien wajib diisi ya.',
            'phone.required' => 'Nomor WhatsApp wajib diisi ya.',
            'phone.regex' => 'Format nomor WhatsApp belum benar (contoh 0812xxxxxxx).',
            'gender.required' => 'Pilih gender dulu ya.',
            'age.required' => 'Umur klien wajib diisi ya.',
            'age.integer' => 'Umur harus berupa angka tahun.',
            'age.min' => 'Umur minimal 5 tahun.',
            'age.max' => 'Umur maksimal 100 tahun.',
            'weight_kg.required' => 'Berat badan wajib diisi ya.',
            'weight_kg.numeric' => 'Berat badan harus berupa angka.',
            'weight_kg.min' => 'Berat badan minimal 20 kg.',
            'goal.required' => 'Pilih tujuan utama dulu ya.',
            'body_focus.required' => 'Pilih minimal satu fokus bagian tubuh ya.',
            'body_focus.min' => 'Pilih minimal satu fokus bagian tubuh ya.',
            'body_focus.*.in' => 'Fokus bagian tubuh tidak valid.',
            'activity_level.required' => 'Pilih tingkat keaktifan di luar sesi PT ya.',
            'pt_frequency_per_week.required' => 'Pilih frekuensi sesi per minggu ya.',
            'remaining_sessions.required' => 'Isi jumlah paket sesi ya.',
        ]);
    }

    /** Normalisasi fokus & hitung rentang protein harian. */
    private function withProtein(array $data): array
    {
        $data['body_focus'] = Client::normalizeFocus($data['body_focus']);
        [$data['daily_protein_min'], $data['daily_protein_max']] = Client::calcProteinRange(
            (float) $data['weight_kg'], $data['goal'], $data['gender'], (int) $data['pt_frequency_per_week'], $data['activity_level']
        );
        $data['daily_protein_target'] = $data['daily_protein_max']; // kolom lama = target optimal
        return $data;
    }

    public function store(Request $request, AiWorkoutService $ai)
    {
        $data = $this->withProtein($this->validateClient($request));
        $result = $ai->generate($data);

        $client = DB::transaction(function () use ($data, $result) {
            $client = Client::create($data);
            $client->weightLogs()->create(['weight_kg' => $data['weight_kg'], 'recorded_at' => now()->toDateString()]);
            foreach ($result['programs'] as $p) {
                $client->programs()->create([
                    'cycle_number' => 1,
                    'day_label' => $p['day_label'],
                    'exercises_json' => $p['exercises'],
                    'is_active' => true,
                ]);
            }
            return $client;
        });

        $via = $result['source'] === 'ai' ? 'dengan bantuan AI' : 'dengan template gerakan';
        return redirect()->route('klien.index')->with('success', "Klien {$client->name} tersimpan & program latihan dibuat {$via} ✨");
    }

    /**
     * Edit semua data klien. Jika `regenerate` = 1, program lama dinonaktifkan dan
     * AI membuat siklus program baru (cycle_number + 1). Riwayat jadwal/sesi tidak dihapus.
     */
    public function update(Request $request, Client $client, AiWorkoutService $ai)
    {
        $data = $this->withProtein($this->validateClient($request));
        $regenerate = $request->boolean('regenerate');

        // Panggilan AI (bisa lambat) dilakukan sebelum transaksi database
        $result = $regenerate ? $ai->generate($data) : null;

        DB::transaction(function () use ($client, $data, $result) {
            $oldWeight = (float) $client->weight_kg;
            $client->update($data);

            // Berat badan berubah -> catat di riwayat
            if (abs($oldWeight - (float) $data['weight_kg']) >= 0.05) {
                if (! $client->weightLogs()->exists()) {
                    $client->weightLogs()->create(['weight_kg' => $oldWeight, 'recorded_at' => $client->created_at->toDateString()]);
                }
                $client->weightLogs()->create(['weight_kg' => $data['weight_kg'], 'recorded_at' => now()->toDateString()]);
            }

            if ($result) {
                $cycle = ((int) $client->programs()->max('cycle_number')) + 1;
                $client->programs()->where('is_active', true)->update(['is_active' => false]);
                $newIds = [];
                foreach ($result['programs'] as $p) {
                    $newIds[] = $client->programs()->create([
                        'cycle_number' => $cycle, 'day_label' => $p['day_label'],
                        'exercises_json' => $p['exercises'], 'is_active' => true,
                    ])->id;
                }
                // Jadwal mendatang yang belum dimulai ikut memakai program baru (bergilir Sesi A, B, ...).
                // Sesi yang sudah selesai/berjalan tidak disentuh, jadi riwayat tetap utuh.
                if ($newIds) {
                    $upcoming = $client->schedules()->where('status', 'scheduled')->whereNull('started_at')
                        ->whereDate('scheduled_date', '>=', now()->toDateString())
                        ->orderBy('scheduled_date')->orderBy('scheduled_time')->get();
                    foreach ($upcoming as $i => $s) {
                        $s->update(['workout_program_id' => $newIds[$i % count($newIds)]]);
                    }
                }
            }
        });

        $msg = "Data {$client->name} berhasil diperbarui ✨";
        if ($result) {
            $via = $result['source'] === 'ai' ? 'dengan bantuan AI' : 'dengan template gerakan';
            $msg .= " Program gerakan baru dibuat {$via}.";
        }
        return redirect()->route('klien.index')->with('success', $msg);
    }

    /** Perbarui berat badan: simpan riwayat & hitung ulang target protein. */
    public function updateWeight(Request $request, Client $client)
    {
        $data = $request->validate([
            'weight_kg' => ['required', 'numeric', 'min:20', 'max:300'],
        ], [
            'weight_kg.required' => 'Berat badan baru wajib diisi ya.',
            'weight_kg.numeric' => 'Berat badan harus berupa angka.',
            'weight_kg.min' => 'Berat badan minimal 20 kg.',
        ]);

        DB::transaction(function () use ($client, $data) {
            if (! $client->weightLogs()->exists()) {
                // Klien lama tanpa riwayat: simpan berat awal sebagai titik awal
                $client->weightLogs()->create(['weight_kg' => $client->weight_kg, 'recorded_at' => $client->created_at->toDateString()]);
            }
            $client->weightLogs()->create(['weight_kg' => $data['weight_kg'], 'recorded_at' => now()->toDateString()]);
            [$min, $max] = Client::calcProteinRange(
                (float) $data['weight_kg'], $client->goal, $client->gender, (int) $client->pt_frequency_per_week, $client->activity_level ?? 'Cukup Aktif'
            );
            $client->update([
                'weight_kg' => $data['weight_kg'],
                'daily_protein_min' => $min,
                'daily_protein_max' => $max,
                'daily_protein_target' => $max,
            ]);
        });

        return back()->with('success', "Berat badan {$client->name} diperbarui, target protein jadi {$client->proteinRangeText()} per hari 💖");
    }

    /** Riwayat sesi selesai + ringkasan progres klien (dimuat saat modal History dibuka). */
    public function history(Client $client)
    {
        $done = $client->schedules()->with('program')->where('status', 'completed')
            ->orderBy('finished_at')->orderBy('scheduled_date')->orderBy('id')->get(); // lama -> baru, untuk hitung kenaikan beban

        $lastKg = []; // beban terakhir per gerakan
        $sessions = $done->map(function ($s) use (&$lastKg) {
            $exercises = [];
            foreach ($s->normalizedPerformance() as $p) { // mendukung format lama (sets_reps teks) & baru (sets/reps)
                $name = $p['name'];
                $kg = $p['kg'];
                $delta = ($kg !== null && isset($lastKg[$name])) ? round($kg - $lastKg[$name], 1) : null;
                if ($kg !== null) {
                    $lastKg[$name] = $kg;
                }
                $exercises[] = [
                    'name' => $name, 'equipment' => $p['equipment'], 'sets' => $p['sets'], 'reps' => $p['reps'],
                    'sets_label' => $p['sets_label'], 'kg' => $kg, 'delta' => $delta,
                    'sets_detail' => $p['sets_detail'], 'detail_label' => $p['detail_label'],
                ];
            }
            $start = $s->started_at ?? $s->finished_at;
            return [
                'id' => $s->id,
                'date_label' => $s->scheduled_date->translatedFormat('l, d F Y'),
                'time' => $start ? $start->format('H:i') : substr($s->scheduled_time, 0, 5),
                'duration' => $s->duration_minutes,
                'program' => $s->program?->day_label ?? 'Latihan Bersama PT',
                'exercises' => $exercises,
                'coach_note' => $s->coach_note,
                'share_url' => $s->share_token ? route('progress.show', $s->share_token) : null,
            ];
        })->reverse()->values(); // tampilkan dari paling baru

        return response()->json([
            'name' => $client->name,
            'total' => $done->count(),
            'remaining' => $client->remaining_sessions,
            'first' => $done->first()?->scheduled_date->translatedFormat('d M Y'),
            'last' => $done->last()?->scheduled_date->translatedFormat('d M Y'),
            'sessions' => $sessions,
        ]);
    }

    public function addSessions(Request $request, Client $client)
    {
        $n = $request->validate(['sessions' => ['required', 'integer', 'min:1', 'max:200']])['sessions'];
        $client->increment('remaining_sessions', $n);
        return back()->with('success', "Paket sesi {$client->name} ditambah {$n}x 💖");
    }

    public function destroy(Client $client)
    {
        $client->delete();
        return back()->with('success', 'Data klien sudah dihapus.');
    }

    /** Daftar gerakan pengganti satu area otot untuk satu gerakan dalam program (JSON, untuk modal Ganti Gerakan). */
    public function exerciseAlternatives(WorkoutProgram $program, int $index, AiWorkoutService $ai): JsonResponse
    {
        $program->loadMissing('client');
        $exercises = $program->exercises_json ?? [];
        return response()->json([
            'current' => $exercises[$index] ?? null,
            'alternatives' => $ai->alternativesFor($program->client, $program->day_label, $exercises, $index),
        ]);
    }

    /** Buat satu gerakan pengganti BARU dengan AI untuk satu posisi gerakan (JSON, untuk modal Ganti Gerakan). */
    public function exerciseAlternativeAi(Request $request, WorkoutProgram $program, int $index, AiWorkoutService $ai): JsonResponse
    {
        $data = $request->validate(['exclude' => ['nullable', 'array', 'max:20'], 'exclude.*' => ['string', 'max:100']]);
        $program->loadMissing('client');
        $exercises = $program->exercises_json ?? [];
        if (! isset($exercises[$index])) {
            return response()->json(['message' => 'Gerakan tidak ditemukan di program ini.'], 404);
        }
        $new = $ai->generateReplacement($program->client, $program->day_label, $exercises, $index, $data['exclude'] ?? []);
        if (! $new) {
            return response()->json(['message' => 'AI belum bisa membuat gerakan baru saat ini. Coba lagi sebentar ya.'], 422);
        }
        return response()->json(['exercise' => $new]);
    }

    /** Alternatif gerakan dari satu area otot (JSON, untuk modal Tambah Gerakan di kedua halaman). */
    public function areaAlternatives(Request $request, AiWorkoutService $ai): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'area' => ['required', 'string', Rule::in($ai->areas())],
            'exclude' => ['nullable', 'array', 'max:30'],
            'exclude.*' => ['string', 'max:100'],
        ]);
        $client = Client::findOrFail($data['client_id']);
        return response()->json(['alternatives' => $ai->alternativesForArea($client, $data['area'], $data['exclude'] ?? [])]);
    }

    /** Buat satu gerakan baru dengan AI untuk satu area otot (JSON, untuk modal Tambah Gerakan). */
    public function areaGenerate(Request $request, AiWorkoutService $ai): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'area' => ['required', 'string', Rule::in($ai->areas())],
            'exclude' => ['nullable', 'array', 'max:30'],
            'exclude.*' => ['string', 'max:100'],
        ]);
        $client = Client::findOrFail($data['client_id']);
        $new = $ai->generateForArea($client, $data['area'], $data['exclude'] ?? []);
        if (! $new) {
            return response()->json(['message' => 'AI belum bisa membuat gerakan baru saat ini. Coba lagi sebentar ya.'], 422);
        }
        return response()->json(['exercise' => $new]);
    }

    /** Alternatif mengganti gerakan berdasarkan nama saja (JSON, untuk gerakan tambahan khusus sesi di live session). */
    public function nameAlternatives(Request $request, AiWorkoutService $ai): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'name' => ['required', 'string', 'max:100'],
            'exclude' => ['nullable', 'array', 'max:30'],
            'exclude.*' => ['string', 'max:100'],
        ]);
        $client = Client::findOrFail($data['client_id']);
        [$area, $alternatives] = $ai->alternativesForName($client, $data['name'], $data['exclude'] ?? []);
        return response()->json(['area' => $area, 'alternatives' => $alternatives]);
    }

    /** Tambah SATU gerakan ke program (manual oleh pelatih dari Lihat Program). */
    public function appendExercise(Request $request, WorkoutProgram $program, AiWorkoutService $ai): JsonResponse
    {
        $data = $request->validate([
            'exercise.name' => ['required', 'string', 'max:100'],
            'exercise.equipment' => ['required', 'string', 'max:100'],
            'exercise.sets_reps' => ['required', 'string', 'max:30'],
            'exercise.target_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'exercise.tip' => ['nullable', 'string', 'max:300'],
        ]);
        $alt = $ai->validateReplacement($data['exercise']);
        if (! $alt) {
            return response()->json(['message' => 'Gerakan itu tidak valid (harus memakai alat yang tersedia).'], 422);
        }
        $exercises = $program->exercises_json ?? [];
        $newKey = ExerciseMedia::keyFor($alt['name']);
        foreach ($exercises as $x) {
            if (ExerciseMedia::keyFor((string) ($x['name'] ?? '')) === $newKey) {
                return response()->json(['message' => 'Gerakan itu sudah ada di sesi ini.'], 422);
            }
        }
        $exercises[] = $alt;
        $exercises = array_values($exercises);
        $program->update(['exercises_json' => $exercises]);
        return response()->json([
            'message' => "Gerakan {$alt['name']} ditambahkan ke {$program->day_label} ✨",
            'exercise' => $alt,
            'exercises' => $exercises,
            'index' => count($exercises) - 1,
        ]);
    }

    /** Hapus SATU gerakan dari program (manual oleh pelatih dari Lihat Program). */
    public function deleteExercise(WorkoutProgram $program, int $index): JsonResponse
    {
        $exercises = $program->exercises_json ?? [];
        if (! isset($exercises[$index])) {
            return response()->json(['message' => 'Gerakan tidak ditemukan di program ini.'], 404);
        }
        if (count($exercises) <= 1) {
            return response()->json(['message' => 'Satu sesi minimal punya 1 gerakan, jadi gerakan terakhir tidak bisa dihapus.'], 422);
        }
        $old = $exercises[$index]['name'];
        array_splice($exercises, $index, 1);
        $exercises = array_values($exercises);
        $program->update(['exercises_json' => $exercises]);
        return response()->json([
            'message' => "Gerakan {$old} dihapus dari program",
            'exercises' => $exercises,
        ]);
    }

    /** Ganti SATU gerakan dalam program dengan gerakan lain (bukan generate ulang). */
    public function swapExercise(Request $request, WorkoutProgram $program, int $index, AiWorkoutService $ai): JsonResponse
    {
        $program->loadMissing('client');
        $exercises = $program->exercises_json ?? [];
        if (! isset($exercises[$index])) {
            return response()->json(['message' => 'Gerakan tidak ditemukan di program ini.'], 404);
        }

        if ($request->has('exercise')) {
            // Pengganti hasil generate AI: divalidasi ulang di server (wajib beralat, alatnya tersedia, tidak dobel di sesi).
            $data = $request->validate([
                'exercise.name' => ['required', 'string', 'max:100'],
                'exercise.equipment' => ['required', 'string', 'max:100'],
                'exercise.sets_reps' => ['required', 'string', 'max:30'],
                'exercise.target_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
                'exercise.tip' => ['nullable', 'string', 'max:300'],
            ]);
            $alt = $ai->validateReplacement($data['exercise']);
            if (! $alt) {
                return response()->json(['message' => 'Gerakan pengganti itu tidak valid (harus memakai alat yang tersedia).'], 422);
            }
            $newKey = ExerciseMedia::keyFor($alt['name']);
            foreach ($exercises as $i => $x) {
                if ($i !== $index && ExerciseMedia::keyFor((string) ($x['name'] ?? '')) === $newKey) {
                    return response()->json(['message' => 'Gerakan itu sudah ada di sesi ini.'], 422);
                }
            }
        } else {
            // Pengganti dari pustaka: nama harus ada di daftar alternatif resmi gerakan ini.
            $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
            $alt = collect($ai->alternativesFor($program->client, $program->day_label, $exercises, $index))
                ->firstWhere('name', $data['name']);
            if (! $alt) {
                return response()->json(['message' => 'Gerakan pengganti itu tidak tersedia untuk gerakan ini.'], 422);
            }
        }

        $old = $exercises[$index]['name'];
        $exercises[$index] = $alt;
        $exercises = array_values($exercises);
        $program->update(['exercises_json' => $exercises]);
        return response()->json([
            'message' => "Gerakan {$old} diganti jadi {$alt['name']} ✨",
            'exercise' => $alt,
            'exercises' => $exercises,
        ]);
    }
}
