<?php

namespace App\Http\Controllers;

use App\Models\Voice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VoiceController extends Controller
{
    /** Types détectés pour les fichiers audio courants et les enregistrements du navigateur. */
    private const MIMES = [
        'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave', 'audio/ogg', 'audio/opus',
        'audio/webm', 'video/webm', 'audio/mp4', 'audio/x-m4a', 'video/mp4', 'audio/flac', 'audio/x-flac', 'audio/aac',
        'application/ogg',
    ];

    public function index(): Response
    {
        return Inertia::render('Voices', [
            'voices' => Voice::with('user:id,name')->latest('id')->get()->map(fn (Voice $voice) => [
                'id' => $voice->id,
                'name' => $voice->name,
                'description' => $voice->description,
                'language' => $voice->language,
                'author' => $voice->user?->name,
                'created_at' => $voice->created_at->toIso8601String(),
                'sample_url' => route('voices.sample', $voice),
            ]),
            'languages' => config('voice.languages'),
            'maxSampleMb' => intdiv(config('voice.max_sample_kb'), 1024),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'language' => ['required', Rule::in(array_keys(config('voice.languages')))],
            'sample' => ['required', 'file', 'max:'.config('voice.max_sample_kb'), 'mimetypes:'.implode(',', self::MIMES)],
            'consent' => ['accepted'],
        ], [
            'name.required' => 'Donnez un nom à cette voix.',
            'sample.required' => 'Ajoutez ou enregistrez un échantillon audio.',
            'sample.mimetypes' => 'Ce fichier n\'est pas un format audio reconnu (WAV, MP3, M4A, OGG, WebM, FLAC).',
            'sample.max' => 'L\'échantillon est trop volumineux.',
            'consent.accepted' => 'Vous devez confirmer que vous avez le droit d\'utiliser cette voix.',
        ]);

        $file = $request->file('sample');
        $path = $file->storeAs('voices', Str::uuid().'.'.($file->extension() ?: 'bin'));

        Voice::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'language' => $data['language'],
            'sample_path' => $path,
            'sample_mime' => $file->getMimeType(),
            'consent_confirmed_at' => now(),
        ]);

        return back()->with('success', 'Voix « '.$data['name'].' » ajoutée.');
    }

    public function sample(Voice $voice): BinaryFileResponse
    {
        abort_unless(Storage::exists($voice->sample_path), 404);

        return response()->file(Storage::path($voice->sample_path), ['Content-Type' => $voice->sample_mime]);
    }

    public function destroy(Voice $voice): RedirectResponse
    {
        $voice->delete();

        return back()->with('success', 'Voix supprimée.');
    }
}
