<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\GenerationSegment;
use App\Models\Voice;
use App\Services\DialogueParser;
use App\Services\TextSplitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
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

    public function store(Request $request, TextSplitter $splitter, DialogueParser $dialogue): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:'.config('voice.max_chars')],
            'language' => ['required', Rule::in(array_keys(config('voice.languages')))],
            'voice_id' => ['nullable', 'integer', 'exists:voices,id'],
            'dialogue' => ['boolean'],
            'exaggeration' => ['required', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['required', 'numeric', 'between:0,1'],
            'temperature' => ['required', 'numeric', 'between:0.05,2'],
            'seed' => ['nullable', 'integer', 'between:0,2147483647'],
            'pause_ms' => ['integer', 'between:0,3000'],
            'paragraph_pause_ms' => ['integer', 'between:0,5000'],
            'normalize' => ['boolean'],
        ], [
            'text.required' => 'Saisissez le texte à lire.',
            'text.max' => 'Le texte dépasse :max caractères.',
        ]);

        $voice = isset($data['voice_id']) ? Voice::find($data['voice_id']) : null;
        $isDialogue = (bool) ($data['dialogue'] ?? false);

        // Un texte simple est un dialogue à une seule voix
        try {
            $lines = $isDialogue
                ? $dialogue->parse($data['text'], Voice::all(), $voice)
                : [['voice' => $voice, 'text' => $data['text']]];
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        }

        $segments = [];
        foreach ($lines as $line) {
            foreach ($splitter->split($line['text'], config('voice.segment_chars')) as $segment) {
                $segments[] = $segment + [
                    'position' => count($segments),
                    'voice_id' => $line['voice']?->id,
                    'voice_name' => $line['voice']?->name,
                ];
            }
        }
        if (! $segments) {
            throw ValidationException::withMessages(['text' => 'Saisissez le texte à lire.']);
        }

        $speakers = collect($segments)->map(fn (array $s) => $s['voice_name'] ?? 'Voix par défaut')->unique()->values();

        $generation = DB::transaction(function () use ($request, $data, $voice, $segments, $isDialogue, $speakers) {
            $generation = Generation::create([
                'user_id' => $request->user()->id,
                'voice_id' => $voice?->id,
                'voice_name' => $speakers->count() > 1 ? Str::limit('Dialogue : '.$speakers->implode(', '), 250) : $segments[0]['voice_name'],
                'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
                'text' => trim($data['text']),
                'language' => $data['language'],
                'settings' => [
                    'exaggeration' => (float) $data['exaggeration'],
                    'cfg_weight' => (float) $data['cfg_weight'],
                    'temperature' => (float) $data['temperature'],
                    'seed' => $data['seed'] ?? null,
                    'pause_ms' => (int) ($data['pause_ms'] ?? config('voice.pause_ms')),
                    'paragraph_pause_ms' => (int) ($data['paragraph_pause_ms'] ?? config('voice.paragraph_pause_ms')),
                    'normalize' => (bool) ($data['normalize'] ?? true),
                    'dialogue' => $isDialogue,
                ],
                'status' => Generation::PENDING,
            ]);

            $generation->segments()->createMany($segments);

            return $generation;
        });

        $generation->enqueue();

        return back();
    }

    /** Renomme l'audio. */
    public function update(Request $request, Generation $generation): RedirectResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'max:120']]);

        $generation->update(['title' => filled($data['title'] ?? null) ? trim($data['title']) : null]);

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

            // Le texte complet suit les corrections apportées aux passages.
            // Celui d'un dialogue garde ses balises de voix : on n'y touche pas.
            if ($generation->isDialogue()) {
                return;
            }
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
        $download = $request->query('download');
        $wav = $download === 'wav';
        $path = $wav ? $generation->wav_path : $generation->audio_path;
        abort_unless($path && Storage::exists($path), 404);

        $type = $wav ? 'audio/wav' : 'audio/mpeg';

        return $download
            ? response()->download(Storage::path($path), $generation->filename().($wav ? '.wav' : '.mp3'), ['Content-Type' => $type])
            : response()->file(Storage::path($path), ['Content-Type' => $type]);
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
