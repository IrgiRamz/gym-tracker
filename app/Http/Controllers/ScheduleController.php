<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Schedule;
use App\Services\GowaWhatsappService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    private function payload(Schedule $s): array
    {
        $s->loadMissing('client', 'program');
        return [
            'id' => $s->id,
            'client_id' => $s->client_id,
            'client_name' => $s->client->name,
            'client_remaining' => $s->client->remaining_sessions,
            'date' => $s->scheduled_date->format('Y-m-d'),
            'time' => substr($s->scheduled_time, 0, 5),
            'status' => $s->status,
            'started_at' => $s->started_at?->toIso8601String(),
            'duration_minutes' => $s->duration_minutes,
            'program_label' => $s->program?->day_label,
            'program_id' => $s->workout_program_id,
            // Tambahkan nilai default set & repetisi (dipecah dari teks target "3 x 12") untuk input live session
            'exercises' => collect($s->program?->exercises_json ?? [])->map(function ($x) {
                [$sets, $reps] = Schedule::parseSetsReps($x['sets_reps'] ?? '');
                return $x + ['default_sets' => $sets, 'default_reps' => $reps];
            })->values()->all(),
            'actual' => $s->actual_performance_json,
            'coach_note' => $s->coach_note,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['required', 'date_format:Y-m']])['month'];
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $items = Schedule::with('client', 'program')
            ->whereBetween('scheduled_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->orderBy('scheduled_date')->orderBy('scheduled_time')->get();

        return response()->json($items->map(fn ($s) => $this->payload($s))->values());
    }

    /** Sesi yang sedang berjalan (sudah dimulai, belum selesai) - untuk melanjutkan setelah refresh. */
    public function running()
    {
        $s = Schedule::with('client', 'program')
            ->where('status', 'scheduled')->whereNotNull('started_at')
            ->latest('started_at')->first();
        if (! $s) {
            // WAJIB badan 'null' yang sebenarnya: response()->json(null) di Laravel menghasilkan {}
            // (objek kosong), yang di JS terbaca sebagai sesi berjalan dan memunculkan panel hantu.
            return response('null', 200, ['Content-Type' => 'application/json']);
        }
        return response()->json($this->payload($s));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'workout_program_id' => ['nullable', 'exists:workout_programs,id'],
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i'],
        ], [
            'client_id.required' => 'Pilih kliennya dulu ya.',
            'scheduled_date.required' => 'Tanggal wajib diisi.',
            'scheduled_time.required' => 'Jam wajib diisi.',
        ]);

        // Transaksi + kunci baris klien agar dua permintaan bersamaan tidak melewati batas sisa sesi
        $s = DB::transaction(function () use ($data) {
            $client = Client::lockForUpdate()->findOrFail($data['client_id']);

            // 1) Sisa sesi sudah habis -> tidak boleh dijadwalkan
            if ($client->remaining_sessions <= 0) {
                throw ValidationException::withMessages([
                    'client_id' => 'Sisa sesi klien ini sudah habis (0). Silakan perpanjang paket sesi di menu Klien terlebih dahulu.',
                ]);
            }
            // 2) Jadwal berstatus scheduled yang sudah dibooking tidak boleh melebihi sisa sesi
            $booked = $client->schedules()->where('status', 'scheduled')->count();
            if ($booked >= $client->remaining_sessions) {
                throw ValidationException::withMessages([
                    'client_id' => "Sisa sesi klien ini ({$client->remaining_sessions}) sudah terpakai semua oleh jadwal yang sudah dibuat. "
                        . 'Selesaikan atau hapus jadwal lama, atau perpanjang paket sesi di menu Klien.',
                ]);
            }

            if (empty($data['workout_program_id'])) {
                $programs = $client->activePrograms()->get();
                if ($programs->isNotEmpty()) {
                    // Rotasi Sesi A, B, C sesuai jumlah jadwal klien sebelumnya
                    $count = $client->schedules()->where('status', '!=', 'cancelled')->count();
                    $data['workout_program_id'] = $programs[$count % $programs->count()]->id;
                }
            }

            return Schedule::create($data + ['status' => 'scheduled']);
        });

        return response()->json($this->payload($s), 201);
    }

    public function destroy(Schedule $schedule): JsonResponse
    {
        abort_if($schedule->status === 'completed', 422, 'Sesi yang sudah selesai tidak bisa dihapus.');
        $schedule->delete();
        return response()->json(['ok' => true]);
    }

    public function start(Schedule $schedule): JsonResponse
    {
        abort_if($schedule->status !== 'scheduled', 422, 'Jadwal ini sudah selesai atau dibatalkan.');
        if (! $schedule->started_at) {
            $schedule->update(['started_at' => now()]);
        }
        return response()->json($this->payload($schedule->fresh()));
    }

    /** Batalkan sesi yang tidak sengaja dimulai: jadwal kembali ke status semula, sesi klien tidak berkurang. */
    public function cancelStart(Schedule $schedule): JsonResponse
    {
        abort_if($schedule->status !== 'scheduled', 422, 'Sesi ini sudah selesai, tidak bisa dibatalkan.');
        $schedule->update(['started_at' => null]);
        return response()->json($this->payload($schedule->fresh()));
    }

    public function finish(Request $request, Schedule $schedule, GowaWhatsappService $gowa): JsonResponse
    {
        abort_if($schedule->status !== 'scheduled', 422, 'Jadwal ini sudah selesai atau dibatalkan.');

        $data = $request->validate([
            'performance' => ['nullable', 'array'],
            'performance.*.name' => ['required', 'string', 'max:150'],
            'performance.*.equipment' => ['nullable', 'string', 'max:150'],
            'performance.*.sets' => ['nullable', 'integer', 'min:0', 'max:99'],
            'performance.*.reps' => ['nullable', 'string', 'max:20'],
            'performance.*.sets_reps' => ['nullable', 'string', 'max:50'],
            'performance.*.actual_kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'performance.*.sets_detail' => ['nullable', 'array', 'max:20'],
            'performance.*.sets_detail.*.reps' => ['nullable', 'string', 'max:20'],
            'performance.*.sets_detail.*.kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'coach_note' => ['nullable', 'string', 'max:1000'],
        ], [
            'performance.*.sets.integer' => 'Jumlah set harus berupa angka bulat.',
            'performance.*.reps.max' => 'Repetisi maksimal 20 karakter.',
            'performance.*.actual_kg.numeric' => 'Beban harus berupa angka.',
        ]);

        // Simpan nilai aktual lapangan utuh (nama & alat ikut disimpan agar tidak bergantung program yang bisa diganti nanti)
        $performance = collect($data['performance'] ?? [])->map(function ($p) {
            // Format per-set: sets_detail = [{reps, kg}, ...]. Agregat (sets/reps/actual_kg) diturunkan
            // darinya agar konsumen lama tetap jalan: sets = jumlah set, actual_kg = beban terberat (top set).
            $detail = collect($p['sets_detail'] ?? [])->map(fn ($sd) => [
                'reps' => isset($sd['reps']) && trim((string) $sd['reps']) !== '' ? trim((string) $sd['reps']) : null,
                'kg' => (float) ($sd['kg'] ?? 0),
            ])->values()->all();
            if ($detail) {
                $sets = count($detail);
                $reps = $detail[0]['reps'];
                return [
                    'name' => $p['name'],
                    'equipment' => $p['equipment'] ?? null,
                    'sets' => $sets,
                    'reps' => $reps,
                    'sets_reps' => $reps !== null ? "{$sets} x {$reps}" : "{$sets} set",
                    'actual_kg' => max(array_column($detail, 'kg')),
                    'sets_detail' => $detail,
                ];
            }
            $sets = isset($p['sets']) && $p['sets'] !== '' ? (int) $p['sets'] : null;
            $reps = isset($p['reps']) && trim((string) $p['reps']) !== '' ? trim((string) $p['reps']) : null;
            return [
                'name' => $p['name'],
                'equipment' => $p['equipment'] ?? null,
                'sets' => $sets,
                'reps' => $reps,
                'sets_reps' => ($sets && $reps !== null) ? "{$sets} x {$reps}" : ($p['sets_reps'] ?? ''), // kompatibilitas format lama
                'actual_kg' => (float) ($p['actual_kg'] ?? 0),
            ];
        })->values()->all();

        $now = now();
        $started = $schedule->started_at ?? $now;
        $minutes = max(1, (int) round($started->diffInSeconds($now) / 60));

        DB::transaction(function () use ($schedule, $data, $performance, $now, $minutes) {
            $schedule->update([
                'status' => 'completed',
                'started_at' => $schedule->started_at ?? $now,
                'finished_at' => $now,
                'duration_minutes' => $minutes,
                'actual_performance_json' => $performance,
                'coach_note' => $data['coach_note'] ?? null,
                'share_token' => $schedule->share_token ?? Str::random(32),
            ]);
            $client = $schedule->client()->lockForUpdate()->first();
            if ($client->remaining_sessions > 0) {
                $client->decrement('remaining_sessions');
            }
        });

        $schedule = $schedule->fresh(['client', 'program']);
        $sent = false;
        try {
            $sent = $gowa->sendMessage($schedule->client->phone, $gowa->buildSessionReport($schedule));
        } catch (\Throwable $e) {
            $sent = false;
        }

        return response()->json([
            'schedule' => $this->payload($schedule),
            'wa_sent' => $sent,
            'message' => $sent
                ? 'Sesi tersimpan & laporan sudah terkirim ke WhatsApp klien 💖'
                : 'Sesi tersimpan ✅ Tapi laporan WhatsApp belum terkirim (GoWA tidak aktif).',
        ]);
    }
}
