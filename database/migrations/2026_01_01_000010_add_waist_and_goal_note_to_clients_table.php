<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('waist_cm')->nullable()->after('height_cm');
            $table->string('goal_note', 200)->nullable()->after('goal');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['waist_cm', 'goal_note']);
        });
    }
};
