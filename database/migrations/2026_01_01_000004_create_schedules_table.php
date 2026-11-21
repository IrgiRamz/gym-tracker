<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_program_id')->nullable()->constrained()->nullOnDelete();
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->string('status')->default('scheduled'); // scheduled, completed, cancelled
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->json('actual_performance_json')->nullable();
            $table->text('coach_note')->nullable();
            $table->timestamps();
            $table->index('scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
