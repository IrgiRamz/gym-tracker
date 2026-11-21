<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('activity_level')->default('Cukup Aktif')->after('pt_frequency_per_week');
            $table->unsignedSmallInteger('daily_protein_min')->default(0)->after('remaining_sessions');
            $table->unsignedSmallInteger('daily_protein_max')->default(0)->after('daily_protein_min');
        });

        // Klien yang sudah ada: hitung rentang protein dari data mereka.
        // daily_protein_target (kolom lama) tetap dipakai = target optimal (batas atas).
        foreach (DB::table('clients')->get() as $c) {
            [$min, $max] = Client::calcProteinRange((float) $c->weight_kg, $c->goal, $c->gender, (int) $c->pt_frequency_per_week);
            DB::table('clients')->where('id', $c->id)->update([
                'daily_protein_min' => $min, 'daily_protein_max' => $max, 'daily_protein_target' => $max,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['activity_level', 'daily_protein_min', 'daily_protein_max']);
        });
    }
};
