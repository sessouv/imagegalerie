<?php

use App\Http\Controllers\GenerationController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\StudioController;
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
});
