<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PronunciationController;
use App\Http\Controllers\StudioController;
use App\Http\Controllers\TranscriptionController;
use App\Http\Controllers\VoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', StudioController::class)->name('studio');

    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('/generations/{generation}/audio', [GenerationController::class, 'audio'])->name('generations.audio');
    Route::patch('/generations/{generation}', [GenerationController::class, 'update'])->name('generations.update');
    Route::delete('/generations/{generation}', [GenerationController::class, 'destroy'])->name('generations.destroy');
    Route::post('/generations/{generation}/retry', [GenerationController::class, 'retry'])->name('generations.retry');
    Route::get('/generations/{generation}/segments', [GenerationController::class, 'segments'])->name('generations.segments');
    Route::scopeBindings()->group(function () {
        Route::get('/generations/{generation}/segments/{segment}/audio', [GenerationController::class, 'segmentAudio'])->name('generations.segments.audio');
        Route::post('/generations/{generation}/segments/{segment}/regenerate', [GenerationController::class, 'regenerateSegment'])->name('generations.segments.regenerate');
    });

    Route::get('/voices', [VoiceController::class, 'index'])->name('voices.index');
    Route::post('/voices', [VoiceController::class, 'store'])->name('voices.store');
    Route::get('/voices/{voice}/sample', [VoiceController::class, 'sample'])->name('voices.sample');
    Route::delete('/voices/{voice}', [VoiceController::class, 'destroy'])->name('voices.destroy');
    Route::put('/voices/{voice}/cleaning', [VoiceController::class, 'cleaning'])->name('voices.cleaning');
    Route::put('/voices/{voice}/settings', [VoiceController::class, 'settings'])->name('voices.settings');

    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::get('/transcriptions', [TranscriptionController::class, 'index'])->name('transcriptions.index');
    Route::post('/transcriptions', [TranscriptionController::class, 'store'])->name('transcriptions.store');
    Route::post('/transcriptions/{transcription}/retry', [TranscriptionController::class, 'retry'])->name('transcriptions.retry');
    Route::get('/transcriptions/{transcription}/download/{format}', [TranscriptionController::class, 'download'])->name('transcriptions.download');
    Route::delete('/transcriptions/{transcription}', [TranscriptionController::class, 'destroy'])->name('transcriptions.destroy');

    Route::get('/api-keys', [ApiTokenController::class, 'index'])->name('api-keys.index');
    Route::post('/api-keys', [ApiTokenController::class, 'store'])->name('api-keys.store');
    Route::delete('/api-keys/{token}', [ApiTokenController::class, 'destroy'])->name('api-keys.destroy');

    Route::get('/pronunciations', [PronunciationController::class, 'index'])->name('pronunciations.index');
    Route::post('/pronunciations', [PronunciationController::class, 'store'])->name('pronunciations.store');
    Route::put('/pronunciations/{pronunciation}', [PronunciationController::class, 'update'])->name('pronunciations.update');
    Route::delete('/pronunciations/{pronunciation}', [PronunciationController::class, 'destroy'])->name('pronunciations.destroy');
});
