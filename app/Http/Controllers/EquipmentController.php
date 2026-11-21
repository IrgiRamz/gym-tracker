<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentController extends Controller
{
    public function index()
    {
        $equipments = Equipment::orderBy('category')->orderBy('name')->get()->groupBy('category');
        return view('equipments', ['grouped' => $equipments, 'categories' => Equipment::CATEGORIES]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(Equipment::CATEGORIES)],
        ], [
            'name.required' => 'Nama alat wajib diisi ya.',
            'category.required' => 'Pilih kategori alatnya dulu ya.',
            'category.in' => 'Kategori tidak valid.',
        ]);

        Equipment::create($data + ['is_available' => true]);
        return back()->with('success', 'Alat berhasil ditambahkan ✨');
    }

    public function toggle(Request $request, Equipment $equipment)
    {
        $equipment->update(['is_available' => ! $equipment->is_available]);

        // AJAX (dari halaman Alat Gym): balas JSON agar halaman tidak reload
        // dan posisi scroll tetap di tempat.
        if ($request->expectsJson()) {
            return response()->json([
                'is_available' => $equipment->is_available,
                'message' => $equipment->is_available
                    ? $equipment->name . ' sekarang tersedia ✨'
                    : $equipment->name . ' dinonaktifkan sementara.',
            ]);
        }

        return back();
    }

    public function destroy(Equipment $equipment)
    {
        $equipment->delete();
        return back()->with('success', 'Alat sudah dihapus.');
    }
}
