<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EquipmentController extends Controller
{
    public function index()
    {
        $equipments = Equipment::orderBy('category')->orderBy('name')->get()->groupBy('category');
        return view('equipments', ['grouped' => $equipments, 'categories' => Equipment::CATEGORIES]);
    }

    /** Aturan validasi foto alat (dipakai tambah alat & ganti foto). */
    private function imageRules(): array
    {
        return ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];
    }

    private function imageMessages(): array
    {
        return [
            'image.image' => 'File foto harus berupa gambar ya.',
            'image.mimes' => 'Format foto harus JPG, PNG, atau WebP ya.',
            'image.max' => 'Ukuran foto maksimal 4 MB ya.',
            'image.uploaded' => 'Foto gagal diunggah, coba lagi ya. Cek juga ukuran fotonya.',
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(Equipment::CATEGORIES)],
            'image' => $this->imageRules(),
        ], [
            'name.required' => 'Nama alat wajib diisi ya.',
            'category.required' => 'Pilih kategori alatnya dulu ya.',
            'category.in' => 'Kategori tidak valid.',
        ] + $this->imageMessages());

        $path = $request->hasFile('image')
            ? $request->file('image')->store('equipments', 'public')
            : null;

        Equipment::create([
            'name' => $data['name'],
            'category' => $data['category'],
            'is_available' => true,
            'image_path' => $path,
        ]);
        return back()->with('success', 'Alat berhasil ditambahkan ✨');
    }

    /** Tambah/ganti foto alat yang sudah ada. */
    public function updatePhoto(Request $request, Equipment $equipment)
    {
        $request->validate([
            'image' => ['required', ...array_slice($this->imageRules(), 1)],
        ], $this->imageMessages());

        if ($equipment->image_path) {
            Storage::disk('public')->delete($equipment->image_path);
        }
        $equipment->update(['image_path' => $request->file('image')->store('equipments', 'public')]);
        return back()->with('success', 'Foto ' . $equipment->name . ' berhasil disimpan 📸');
    }

    /** Hapus foto alat (alatnya tetap ada). */
    public function destroyPhoto(Equipment $equipment)
    {
        if ($equipment->image_path) {
            Storage::disk('public')->delete($equipment->image_path);
            $equipment->update(['image_path' => null]);
        }
        return back()->with('success', 'Foto ' . $equipment->name . ' sudah dihapus.');
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
        if ($equipment->image_path) {
            Storage::disk('public')->delete($equipment->image_path);
        }
        $equipment->delete();
        return back()->with('success', 'Alat sudah dihapus.');
    }

    public function reset()
    {
        Storage::disk('public')->deleteDirectory('equipments');
        Equipment::query()->delete();
        return back()->with('success', 'Semua alat sudah dihapus. Daftarnya kosong lagi, silakan input ulang dari awal ya 🌸');
    }
}
