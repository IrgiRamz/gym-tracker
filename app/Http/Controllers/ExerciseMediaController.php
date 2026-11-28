<?php

namespace App\Http\Controllers;

use App\Models\ExerciseMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExerciseMediaController extends Controller
{
    /** Aturan validasi media gerakan: video pendek, GIF, atau gambar. Maks 6 MB (di bawah batas unggah server 8 MB). */
    private function mediaRules(): array
    {
        return ['required', 'file', 'mimes:mp4,webm,mov,gif,jpg,jpeg,png,webp', 'max:6144'];
    }

    private function mediaMessages(): array
    {
        return [
            'media.required' => 'Pilih file medianya dulu ya.',
            'media.file' => 'File media tidak terbaca, coba lagi ya.',
            'media.mimes' => 'Format media harus MP4, WebM, MOV, GIF, JPG, PNG, atau WebP ya.',
            'media.max' => 'Ukuran media maksimal 6 MB ya. Pakai video pendek yang muter (loop) saja.',
            'media.uploaded' => 'Media gagal diunggah, coba lagi ya. Cek juga ukuran filenya.',
        ];
    }

    /** Tambah media baru atau ganti media gerakan yang sudah ada (dicocokkan dari nama gerakan). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'media' => $this->mediaRules(),
        ], [
            'name.required' => 'Nama gerakannya wajib diisi ya.',
        ] + $this->mediaMessages());

        $media = ExerciseMedia::firstOrNew(['name_key' => ExerciseMedia::keyFor($data['name'])]);
        $media->name = trim($data['name']);
        if ($media->media_path) {
            Storage::disk('public')->delete($media->media_path);
            ExerciseMedia::deleteThumb($media->media_path);
        }
        $media->media_path = $request->file('media')->store('gerakan', 'public');
        $media->save();
        ExerciseMedia::makeThumb($media->media_path);

        return back()->with('success', 'Media gerakan ' . $media->name . ' berhasil disimpan 🎬');
    }

    /** Hapus media gerakan (barisnya ikut dihapus; gerakan di program tidak terpengaruh). */
    public function destroy(ExerciseMedia $exerciseMedia)
    {
        if ($exerciseMedia->media_path) {
            Storage::disk('public')->delete($exerciseMedia->media_path);
            ExerciseMedia::deleteThumb($exerciseMedia->media_path);
        }
        $name = $exerciseMedia->name;
        $exerciseMedia->delete();
        return back()->with('success', 'Media gerakan ' . $name . ' sudah dihapus.');
    }
}
