<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\Transcription;
use App\Services\EngineJobLost;
use App\Services\VoiceEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Suit une transcription confiée au moteur : envoi du fichier, puis points
 * d'étape réguliers jusqu'au résultat. Chaque passage est court, ce qui
 * permet les fichiers longs.
 */
class TranscribeAudio implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 630;

    public bool $deleteWhenMissingModels = true;

    private const POLL_SECONDS = 3;

    public function __construct(public Transcription $transcription) {}

    public function handle(VoiceEngine $engine): void
    {
        $transcription = $this->transcription;
        if (! $transcription->isRunning()) {
            return;
        }

        try {
            if (! $transcription->engine_job_id) {
                $id = $engine->startTranscription(
                    Storage::path($transcription->audio_path),
                    basename($transcription->audio_path),
                    $transcription->language,
                );
                $transcription->update(['engine_job_id' => $id, 'status' => Generation::PROCESSING]);
            } else {
                $state = $engine->transcriptionStatus($transcription->engine_job_id);

                if ($state['status'] === 'completed') {
                    $transcription->update([
                        'status' => Generation::COMPLETED,
                        'progress' => 100,
                        'detected_language' => $state['language'] ?? null,
                        'duration_ms' => $state['duration_ms'] ?? null,
                        'text' => $state['text'] ?? '',
                        'segments' => $state['segments'] ?? [],
                        'error' => null,
                    ]);

                    return;
                }
                if ($state['status'] === 'failed') {
                    $this->markFailed($state['error'] ?? 'La transcription a échoué.');

                    return;
                }
                $transcription->update(['progress' => (int) ($state['progress'] ?? 0)]);
            }
        } catch (EngineJobLost) {
            // Le moteur a redémarré : il a oublié la tâche, on renvoie le fichier
            $transcription->update(['engine_job_id' => null, 'progress' => 0]);
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($e->getMessage());

            return;
        }

        if (Transcription::whereKey($transcription->id)->exists()) {
            self::dispatch($transcription)->delay(self::POLL_SECONDS);
        }
    }

    public function failed(?Throwable $e): void
    {
        $this->markFailed('La transcription a été interrompue. Cliquez sur « Relancer ».');
    }

    private function markFailed(string $message): void
    {
        Transcription::whereKey($this->transcription->id)->update([
            'status' => Generation::FAILED,
            'engine_job_id' => null,
            'error' => Str::limit($message ?: 'Erreur inconnue.', 480),
        ]);
    }
}
