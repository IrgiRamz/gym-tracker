<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ubah nilai lama (teks tunggal) menjadi array JSON sebelum kolom diubah ke json
        $map = [
            'Kaki & Bokong' => ['Bokong (Glutes)', 'Kaki & Paha'],
            'Lengan & Punggung' => ['Punggung (Back)', 'Lengan & Bahu'],
        ];
        foreach (DB::table('clients')->get(['id', 'body_focus']) as $row) {
            $val = $map[$row->body_focus] ?? [$row->body_focus];
            DB::table('clients')->where('id', $row->id)->update(['body_focus' => json_encode($val, JSON_UNESCAPED_UNICODE)]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->json('body_focus')->change();
        });
    }

    public function down(): void
    {
        // Ubah tipe kolom dulu (nilai JSON tetap sebagai teks), baru ambil fokus pertama
        Schema::table('clients', function (Blueprint $table) {
            $table->string('body_focus')->change();
        });
        foreach (DB::table('clients')->get(['id', 'body_focus']) as $row) {
            $arr = json_decode($row->body_focus, true) ?: [];
            DB::table('clients')->where('id', $row->id)->update(['body_focus' => $arr[0] ?? 'Seluruh Tubuh']);
        }
    }
};
