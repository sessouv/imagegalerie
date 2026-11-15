<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\GenerationSegment;
use App\Models\Voice;
use App\Services\TextSplitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GenerationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('History', [
            'generations' => Generation::withProgress()->latest('id')->paginate(20)
                ->through(fn (Generation $g) => $g->present()),
        ]);
    }

    public function store(Request $request, TextSplitter $splitter): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.config('voice.max_chars')],
            'language' => ['required', Rule::in(array_keys(config('voice.languages')))],
            'voice_id' => ['nullable', 'integer', 'exists:voices,id'],
            'exaggeration' => ['required', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['required', 'numeric', 'between:0,1'],
            'temperature' => ['required', 'numeric', 'between:0.05,2'],
            'seed' => ['nullable', 'integer', 'between:0,2147483647'],
        ], [
            'text.required' => 'Saisissez le texte à lire.',
            'text.max' => 'Le texte dépasse :max caractères.',
        ]);

        $segments = $splitter->split($data['text'], config('voice.segment_chars'));
        if (! $segments) {
            throw ValidationException::withMessages(['text' => 'Saisissez le texte à lire.']);
        }

        $voice = isset($data['voice_id']) ? Voice::find($data['voice_id']) : null;

        $generation = DB::transaction(function () use ($request, $data, $voice, $segments) {
            $generation = Generation::create([
                'user_id' => $request->user()->id,
                'voice_id' => $voice?->id,
                'voice_name' => $voice?->name,
                'text' => trim($data['text']),
                'language' => $data['language'],
                'settings' => [
                    'exaggeration' => (float) $data['exaggeration'],
                    'cfg_weight' => (float) $data['cfg_weight'],
                    'temperature' => (float) $data['temperature'],
                    'seed' => $data['seed'] ?? null,
                ],
                'status' => Generation::PENDING,
            ]);

            $generation->segments()->createMany(
                array_map(fn (array $segment, int $i) => $segment + ['position' => $i], $segments, array_keys($segments)),
            );

            return $generation;
        });

        $generation->enqueue();

        return back();
    }

    /** Reprend une génération en échec, là où elle s'est arrêtée. */
    public function retry(Generation $generation): RedirectResponse
    {
        abort_unless($generation->status === Generation::FAILED, 409);

        $generation->segments()->where('status', '!=', Generation::COMPLETED)
            ->update(['status' => Generation::PENDING, 'error' => null]);
        $generation->enqueue();

        return back();
    }

    public function segments(Generation $generation): JsonResponse
    {
        return response()->json([
            'status' => $generation->status,
            'segments' => $generation->segments()->get()->map->present(),
        ]);
    }

    /** Régénère un seul passage (texte éventuellement corrigé), puis réassemble l'audio. */
    public function regenerateSegment(Request $request, Generation $generation, GenerationSegment $segment): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:1000'],
        ], [
            'text.required' => 'Le passage ne peut pas être vide.',
            'text.max' => 'Un passage ne peut pas dépasser :max caractères.',
        ]);

        $text = trim(preg_replace('/\s+/u', ' ', $data['text']));

        DB::transaction(function () use ($generation, $segment, $text) {
            $segment->update(['text' => $text, 'status' => Generation::PENDING, 'error' => null]);

            // Le texte complet suit les corrections apportées aux passages
            $full = '';
            foreach ($generation->segments()->get() as $s) {
                $full .= $s->text.($s->ends_paragraph ? "\n" : ' ');
            }
            $generation->update(['text' => trim($full)]);
        });

        $generation->enqueue();

        return $this->segments($generation->refresh());
    }

    public function audio(Request $request, Generation $generation): BinaryFileResponse
    {
        abort_unless($generation->audio_path && Storage::exists($generation->audio_path), 404);

        $name = 'voix-'.$generation->id.'.mp3';
        $path = Storage::path($generation->audio_path);

        return $request->boolean('download')
            ? response()->download($path, $name, ['Content-Type' => 'audio/mpeg'])
            : response()->file($path, ['Content-Type' => 'audio/mpeg']);
    }

    public function segmentAudio(Generation $generation, GenerationSegment $segment): BinaryFileResponse
    {
        abort_unless($segment->audio_path && Storage::exists($segment->audio_path), 404);

        return response()->file(Storage::path($segment->audio_path), ['Content-Type' => 'audio/wav']);
    }

    public function destroy(Generation $generation): RedirectResponse
    {
        $generation->delete();

        return back();
    }
}
