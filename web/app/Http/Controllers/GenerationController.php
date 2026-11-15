<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateSpeech;
use App\Models\Generation;
use App\Models\Voice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GenerationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('History', [
            'generations' => Generation::with('user:id,name')->latest('id')->paginate(20)
                ->through(fn (Generation $g) => $g->present()),
        ]);
    }

    public function store(Request $request): RedirectResponse
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

        $voice = isset($data['voice_id']) ? Voice::find($data['voice_id']) : null;

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

        GenerateSpeech::dispatch($generation);

        return back();
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

    public function destroy(Generation $generation): RedirectResponse
    {
        $generation->delete();

        return back();
    }
}
