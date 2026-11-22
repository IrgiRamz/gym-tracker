<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\ExerciseMedia;
use App\Services\GowaWhatsappService;

class DashboardController extends Controller
{
    public function index()
    {
        $clients = Client::with('activePrograms')
            ->withCount(['schedules as booked_count' => fn ($q) => $q->where('status', 'scheduled')])
            ->orderBy('name')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'remaining' => (int) $c->remaining_sessions,
            'booked' => (int) $c->booked_count, // jadwal scheduled yang sudah dibooking
            'programs' => $c->activePrograms->map(fn ($p) => ['id' => $p->id, 'label' => $p->day_label])->values(),
        ]);

        return view('calendar', ['clients' => $clients, 'equipmentImages' => Equipment::imageMap(), 'exerciseMedia' => ExerciseMedia::mediaMap()]);
    }

    public function waStatus(GowaWhatsappService $gowa)
    {
        // Memakai cache 2 menit; request ke GoWA hanya terjadi saat cache kedaluwarsa
        return response()->json(['active' => $gowa->checkStatus()]);
    }
}
