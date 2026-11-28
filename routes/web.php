<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\ExerciseMediaController;
use App\Http\Controllers\PublicProgressController;
use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

// Publik: kartu progres yang dibagikan ke klien (tanpa login)
Route::get('/progress/{token}', [PublicProgressController::class, 'show'])->name('progress.show');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('kalender');
    Route::get('/kalender', [DashboardController::class, 'index']);
    Route::get('/wa-status', [DashboardController::class, 'waStatus'])->name('wa.status');

    Route::get('/klien', [ClientController::class, 'index'])->name('klien.index');
    Route::post('/klien', [ClientController::class, 'store'])->name('klien.store');
    Route::get('/klien/{client}/riwayat', [ClientController::class, 'history'])->name('klien.history');
    Route::patch('/klien/{client}',[ClientController::class, 'update'])->name('klien.update');
    Route::patch('/klien/{client}/berat', [ClientController::class, 'updateWeight'])->name('klien.weight');
    Route::post('/klien/{client}/sesi', [ClientController::class, 'addSessions'])->name('klien.sessions');
    Route::delete('/klien/{client}', [ClientController::class, 'destroy'])->name('klien.destroy');
    Route::get('/program/{program}/gerakan/{index}/alternatif', [ClientController::class, 'exerciseAlternatives'])->name('program.alternatif');
    Route::post('/program/{program}/gerakan/{index}/alternatif-ai', [ClientController::class, 'exerciseAlternativeAi'])->name('program.alternatifAi');
    Route::post('/program/{program}/gerakan', [ClientController::class, 'appendExercise'])->name('program.tambahGerakan');
    Route::patch('/program/{program}/urutan-gerakan', [ClientController::class, 'reorderExercises'])->name('program.urutanGerakan');
    Route::delete('/program/{program}/gerakan/{index}', [ClientController::class, 'deleteExercise'])->name('program.hapusGerakan');
    Route::post('/gerakan/alternatif-area', [ClientController::class, 'areaAlternatives'])->name('gerakan.alternatifArea');
    Route::post('/gerakan/generate-area', [ClientController::class, 'areaGenerate'])->name('gerakan.generateArea');
    Route::post('/gerakan/alternatif-nama', [ClientController::class, 'nameAlternatives'])->name('gerakan.alternatifNama');
    Route::patch('/program/{program}/gerakan/{index}', [ClientController::class, 'swapExercise'])->name('program.ganti');

    Route::get('/alat', [EquipmentController::class, 'index'])->name('alat.index');
    Route::post('/alat', [EquipmentController::class, 'store'])->name('alat.store');
    Route::patch('/alat/{equipment}/toggle', [EquipmentController::class, 'toggle'])->name('alat.toggle');
    Route::patch('/alat/{equipment}/foto', [EquipmentController::class, 'updatePhoto'])->name('alat.foto');
    Route::delete('/alat/{equipment}/foto', [EquipmentController::class, 'destroyPhoto'])->name('alat.foto.hapus');
    Route::delete('/alat/{equipment}', [EquipmentController::class, 'destroy'])->name('alat.destroy');
    Route::delete('/alat-reset', [EquipmentController::class, 'reset'])->name('alat.reset');

    Route::post('/gerakan-media', [ExerciseMediaController::class, 'store'])->name('gerakan.store');
    Route::delete('/gerakan-media/{exerciseMedia}', [ExerciseMediaController::class, 'destroy'])->name('gerakan.destroy');

    Route::get('/jadwal', [ScheduleController::class, 'index'])->name('jadwal.index');
    Route::get('/jadwal/berjalan', [ScheduleController::class, 'running'])->name('jadwal.running');
    Route::post('/jadwal', [ScheduleController::class, 'store'])->name('jadwal.store');
    Route::delete('/jadwal/{schedule}', [ScheduleController::class, 'destroy'])->name('jadwal.destroy');
    Route::get('/jadwal/{schedule}/rencana', [ScheduleController::class, 'plan'])->name('jadwal.plan');
    Route::post('/jadwal/{schedule}/mulai', [ScheduleController::class, 'start'])->name('jadwal.start');
    Route::post('/jadwal/{schedule}/batal-mulai', [ScheduleController::class, 'cancelStart'])->name('jadwal.cancelStart');
    Route::post('/jadwal/{schedule}/selesai', [ScheduleController::class, 'finish'])->name('jadwal.finish');
});
