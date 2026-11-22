<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_media', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // nama gerakan seperti di program, mis. "Pec Deck"
            $table->string('name_key')->unique();   // nama ternormalisasi untuk pencocokan (lihat ExerciseMedia::keyFor)
            $table->string('media_path')->nullable(); // file contoh gerakan di disk public (folder gerakan)
            $table->string('credit')->nullable();     // atribusi sumber media (mis. foto CC dari wger), ditampilkan kecil di modal
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_media');
    }
};
