<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\VoiceEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateSpeech implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 960;

    public function __construct(public Generation $generation) {}

    public function handle(VoiceEngine $engine): void
    {
        $generation = $this->generation;
        $generation->update(['status' => Generation::PROCESSING]);

        $sample = null;
        if ($voice = $generation->voice) {
            $sample = [
                'contents' => Storage::get($voice->sample_path),
                'filename' => basename($voice->sample_path),
            ];
        }

        $result = $engine->speech($generation->text, $generation->language, $generation->settings, $sample);

        $path = 'generations/'.Str::uuid().'.mp3';
        Storage::put($path, $result['audio']);

        $generation->update([
            'status' => Generation::COMPLETED,
            'audio_path' => $path,
            'duration_ms' => $result['duration_ms'],
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $this->generation->update([
            'status' => Generation::FAILED,
            'error' => Str::limit($e?->getMessage() ?: 'Erreur inconnue.', 480),
        ]);
    }
}
