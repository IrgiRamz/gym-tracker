<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('gender');
            $table->decimal('weight_kg', 5, 1);
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->string('goal');
            $table->string('body_focus');
            $table->unsignedTinyInteger('pt_frequency_per_week')->default(2);
            $table->unsignedSmallInteger('remaining_sessions')->default(0);
            $table->unsignedSmallInteger('daily_protein_target')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
