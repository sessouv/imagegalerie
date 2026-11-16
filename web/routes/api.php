<?php

use App\Http\Controllers\Api\GenerationController;
use App\Http\Controllers\Api\TranscriptionController;
use App\Http\Controllers\Api\VoiceController;
use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([AuthenticateApiToken::class, 'throttle:120,1'])->name('api.')->group(function () {
    Route::get('/voices', [VoiceController::class, 'index'])->name('voices.index');

    Route::post('/generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('/generations/{generation}', [GenerationController::class, 'show'])->name('generations.show');
    Route::get('/generations/{generation}/audio', [GenerationController::class, 'audio'])->name('generations.audio');
    Route::delete('/generations/{generation}', [GenerationController::class, 'destroy'])->name('generations.destroy');

    Route::post('/transcriptions', [TranscriptionController::class, 'store'])->name('transcriptions.store');
    Route::get('/transcriptions/{transcription}', [TranscriptionController::class, 'show'])->name('transcriptions.show');
    Route::delete('/transcriptions/{transcription}', [TranscriptionController::class, 'destroy'])->name('transcriptions.destroy');
});
