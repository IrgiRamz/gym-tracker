<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AiWorkoutService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'nailsalamah02@gmail.com'], [
            'name' => 'neil',
            'password' => Hash::make('Nailsayang123.'),
        ]);

        $equipments = [
            ['Dumbbell (1 - 25 kg)', 'Beban Bebas'],
            ['Barbell & Plate', 'Beban Bebas'],
            ['Mesin Leg Press', 'Mesin Latihan'],
            ['Lat Pulldown & Seated Row', 'Mesin Latihan'],
            ['Treadmill', 'Kardio & Matras'],
            ['Matras Yoga', 'Kardio & Matras'],
        ];
        foreach ($equipments as [$name, $cat]) {
            Equipment::firstOrCreate(['name' => $name], ['category' => $cat, 'is_available' => true]);
        }

        $names = Equipment::where('is_available', true)->pluck('name')->all();
        $ai = new AiWorkoutService();

        $samples = [
            ['Aulia Rahma', '081234567801', 54.0, 160, 'Bentuk & Kencangkan', ['Bokong (Glutes)', 'Kaki & Paha'], 2, 10, 'Pemula, pernah cedera lutut ringan.'],
            ['Sinta Maharani', '081234567802', 68.5, 163, 'Turun Berat Badan', ['Bokong (Glutes)', 'Punggung (Back)', 'Perut & Pinggang'], 3, 12, null],
            ['Dewi Anggraini', '081234567803', 50.0, 157, 'Bugar & Sehat', ['Seluruh Tubuh'], 1, 8, 'Suka latihan pagi.'],
        ];

        $clients = [];
        foreach ($samples as [$name, $phone, $w, $h, $goal, $focus, $freq, $remaining, $notes]) {
            $c = Client::firstOrCreate(['phone' => $phone], [
                'name' => $name, 'gender' => 'Wanita', 'weight_kg' => $w, 'height_cm' => $h,
                'goal' => $goal, 'body_focus' => $focus, 'pt_frequency_per_week' => $freq,
                'remaining_sessions' => $remaining,
                'activity_level' => 'Cukup Aktif',
                'daily_protein_min' => Client::calcProteinRange($w, $goal, 'Wanita', $freq)[0],
                'daily_protein_max' => Client::calcProteinRange($w, $goal, 'Wanita', $freq)[1],
                'daily_protein_target' => Client::calcProteinRange($w, $goal, 'Wanita', $freq)[1],
                'notes' => $notes,
            ]);
            if ($c->programs()->count() === 0) {
                foreach ($ai->fallback($c->toArray(), $names, $freq) as $p) {
                    $c->programs()->create([
                        'cycle_number' => 1, 'day_label' => $p['day_label'],
                        'exercises_json' => $p['exercises'], 'is_active' => true,
                    ]);
                }
            }
            $clients[] = $c->load('activePrograms');
        }

        if (Schedule::count() === 0) {
            $today = now();
            // [indeks klien, offset hari, jam, status]
            $plan = [
                [0, -5, '09:00', 'completed'],
                [1, -3, '16:00', 'completed'],
                [0, -1, '09:00', 'completed'],
                [0, 0, '08:00', 'scheduled'],
                [1, 0, '17:00', 'scheduled'],
                [2, 2, '07:30', 'scheduled'],
                [1, 3, '16:00', 'scheduled'],
                [0, 5, '09:00', 'scheduled'],
                [2, 9, '07:30', 'scheduled'],
            ];
            foreach ($plan as $i => [$ci, $off, $time, $status]) {
                $client = $clients[$ci];
                $prog = $client->activePrograms[$i % $client->activePrograms->count()];
                $data = [
                    'client_id' => $client->id,
                    'workout_program_id' => $prog->id,
                    'scheduled_date' => $today->copy()->addDays($off)->toDateString(),
                    'scheduled_time' => $time,
                    'status' => $status,
                ];
                if ($status === 'completed') {
                    $start = $today->copy()->addDays($off)->setTimeFromTimeString($time);
                    $data += [
                        'started_at' => $start,
                        'finished_at' => $start->copy()->addMinutes(55),
                        'duration_minutes' => 55,
                        'actual_performance_json' => collect($prog->exercises_json)->map(function ($e) {
                            [$sets, $reps] = Schedule::parseSetsReps($e['sets_reps']);
                            return [
                                'name' => $e['name'], 'equipment' => $e['equipment'], 'sets' => $sets, 'reps' => $reps,
                                'sets_reps' => $e['sets_reps'], 'actual_kg' => $e['target_kg'],
                            ];
                        })->all(),
                        'coach_note' => 'Kerja bagus, pertahankan ya!',
                        'share_token' => Str::random(32),
                    ];
                }
                Schedule::create($data);
            }
        }
    }
}
