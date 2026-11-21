<?php

namespace App\Http\Controllers;

use App\Models\Schedule;

class PublicProgressController extends Controller
{
    public function show(string $token)
    {
        $schedule = Schedule::with('client', 'program')
            ->where('share_token', $token)
            ->where('status', 'completed')
            ->firstOrFail();

        $client = $schedule->client;
        $done = $client->schedules()->where('status', 'completed')->count();
        // Urutan sesi ini di antara sesi selesai klien
        $number = $client->schedules()->where('status', 'completed')
            ->where(fn ($q) => $q->where('finished_at', '<', $schedule->finished_at)
                ->orWhere(fn ($q2) => $q2->where('finished_at', $schedule->finished_at)->where('id', '<=', $schedule->id)))
            ->count();

        return view('public.progress', [
            's' => $schedule,
            'client' => $client,
            'sessionNo' => max(1, $number),
            'sessionTotal' => $done + $client->remaining_sessions,
        ]);
    }
}
