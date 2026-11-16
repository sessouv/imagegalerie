<?php

namespace App\Console\Commands;

use App\Jobs\GenerateSpeech;
use App\Jobs\TranscribeAudio;
use App\Models\Generation;
use App\Models\Transcription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResumeGenerations extends Command
{
    protected $signature = 'generations:resume';

    protected $description = 'Reprend les générations interrompues. À lancer avant le worker, quand aucun worker ne tourne.';

    public function handle(): int
    {
        // Les étapes restées en file sont remplacées par une étape neuve par génération
        if (config('queue.default') === 'database') {
            DB::table('jobs')->where('payload', 'like', '%GenerateSpeech%')->orWhere('payload', 'like', '%TranscribeAudio%')->delete();
        }

        $generations = Generation::whereIn('status', [Generation::PENDING, Generation::PROCESSING])->get();

        foreach ($generations as $generation) {
            $generation->segments()->where('status', Generation::PROCESSING)->update(['status' => Generation::PENDING]);
            $generation->update(['status' => Generation::PENDING]);
            GenerateSpeech::dispatch($generation);
        }

        $transcriptions = Transcription::whereIn('status', [Generation::PENDING, Generation::PROCESSING])->get();
        $transcriptions->each(fn (Transcription $transcription) => TranscribeAudio::dispatch($transcription));

        $this->info($generations->count().' génération(s) et '.$transcriptions->count().' transcription(s) reprise(s).');

        return self::SUCCESS;
    }
}
