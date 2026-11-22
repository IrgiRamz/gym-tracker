<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Equipment;
use App\Services\AiWorkoutService;
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
        ]);
    }

    /** Aturan validasi yang sama untuk tambah & edit klien. */
    private function validateClient(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\s-]{9,18}$/'],
            'gender' => ['required', Rule::in(['Wanita', 'Pria'])],
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
}
