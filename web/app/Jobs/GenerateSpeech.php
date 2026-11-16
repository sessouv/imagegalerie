<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\GenerationSegment;
use App\Services\PronunciationDictionary;
use App\Services\VoiceEngine;
use App\Services\WavJoiner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fait avancer une génération d'une étape : un passage, ou l'assemblage final.
 * Chaque étape relance la suivante, ce qui permet les textes très longs et la
 * reprise après un arrêt du worker.
 */
class GenerateSpeech implements ShouldQueue
{
    use Queueable;

    // Une étape interrompue (arrêt du PC, du worker) est retentée automatiquement
    public int $tries = 3;

    public int $timeout = 630;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Generation $generation) {}

    public function handle(VoiceEngine $engine, WavJoiner $joiner, PronunciationDictionary $dictionary): void
    {
        $generation = $this->generation;
        if (! $generation->isRunning()) {
            return;
        }

        $segment = $this->nextSegment();

        try {
            if ($segment) {
                $generation->update(['status' => Generation::PROCESSING]);
                $this->render($segment, $engine, $dictionary);
            } else {
                $this->assemble($engine, $joiner);
            }
        } catch (Throwable $e) {
            report($e);
            $this->markFailed($e->getMessage(), $segment);

            return;
        }

        // Supprimée ou arrêtée pendant le traitement : on s'arrête là
        $current = Generation::find($generation->id);
        if (! $current) {
            Storage::deleteDirectory($generation->directory());

            return;
        }

        if ($segment || $this->nextSegment()) {
            if ($current->isRunning()) {
                self::dispatch($current);
            }

            return;
        }

        $current->update(['status' => Generation::COMPLETED, 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->markFailed('La génération a été interrompue. Cliquez sur « Reprendre ».');
    }

    private function nextSegment(): ?GenerationSegment
    {
        return $this->generation->segments()->where('status', '!=', Generation::COMPLETED)->first();
    }

    private function render(GenerationSegment $segment, VoiceEngine $engine, PronunciationDictionary $dictionary): void
    {
        $generation = $this->generation;
        $segment->update(['status' => Generation::PROCESSING, 'error' => null]);

        // La voix est celle du passage : dans un dialogue, elle change d'une réplique à l'autre
        $voice = $segment->voice;
        if (! $voice && $segment->voice_name) {
            throw new RuntimeException('La voix « '.$segment->voice_name.' » a été supprimée.');
        }
        $sample = null;
        if ($voice) {
            $path = $voice->activeSamplePath();
            $sample = ['contents' => Storage::get($path), 'filename' => basename($path)];
        }

        // Dans un dialogue, chaque voix garde ses réglages favoris
        $settings = $generation->engineSettings();
        if ($generation->isDialogue() && $voice?->settings) {
            $settings = $voice->settings + $settings;
        }
        // Avec une graine fixe, chaque nouvel essai d'un passage doit tout de même varier
        if (isset($settings['seed'])) {
            $settings['seed'] = ($settings['seed'] + $segment->attempts) % 2147483647;
        }

        $spoken = $dictionary->apply($segment->text, $generation->language);
        $result = $engine->speech($spoken, $generation->language, $settings, $sample);

        $path = $generation->directory().'/passage-'.$segment->id.'-'.Str::random(8).'.wav';
        Storage::put($path, $result['audio']);

        $previous = $segment->audio_path;
        $segment->update([
            'status' => Generation::COMPLETED,
            'audio_path' => $path,
            'duration_ms' => $result['duration_ms'],
            'attempts' => $segment->attempts + 1,
        ]);
        if ($previous) {
            Storage::delete($previous);
        }
    }

    private function assemble(VoiceEngine $engine, WavJoiner $joiner): void
    {
        $generation = $this->generation;
        $settings = $generation->settings;
        $pause = (int) ($settings['pause_ms'] ?? config('voice.pause_ms'));
        $paragraphPause = (int) ($settings['paragraph_pause_ms'] ?? config('voice.paragraph_pause_ms'));

        $parts = $generation->segments()->get()->map(fn (GenerationSegment $segment) => [
            'path' => Storage::path($segment->audio_path),
            'pause_ms' => $segment->ends_paragraph ? $paragraphPause : $pause,
        ])->all();

        $suffix = Str::random(8);
        $mp3 = $generation->directory().'/audio-'.$suffix.'.mp3';
        $wav = $generation->directory().'/audio-'.$suffix.'.wav';
        $raw = Storage::path($generation->directory().'/assemblage.wav');

        try {
            $duration = $joiner->join($parts, $raw);
            if ($settings['normalize'] ?? false) {
                $engine->encode($raw, Storage::path($wav), 'wav', normalize: true);
            } else {
                rename($raw, Storage::path($wav));
            }
            $engine->encode(Storage::path($wav), Storage::path($mp3), 'mp3');
        } catch (Throwable $e) {
            Storage::delete([$wav, $mp3]);
            throw $e;
        } finally {
            @unlink($raw);
        }

        $previous = array_filter([$generation->audio_path, $generation->wav_path]);
        $generation->update(['audio_path' => $mp3, 'wav_path' => $wav, 'duration_ms' => $duration]);
        Storage::delete($previous);
    }

    private function markFailed(string $message, ?GenerationSegment $segment = null): void
    {
        $error = Str::limit($message ?: 'Erreur inconnue.', 480);

        $this->generation->segments()->where('status', Generation::PROCESSING)
            ->update(['status' => Generation::FAILED, 'error' => $segment ? $error : null]);
        Generation::whereKey($this->generation->id)->update(['status' => Generation::FAILED, 'error' => $error]);
    }
}
