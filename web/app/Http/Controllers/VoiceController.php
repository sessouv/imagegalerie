<?php

namespace App\Http\Controllers;

use App\Models\Voice;
use App\Services\VoiceEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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
                'cleaned' => $voice->usesCleaned(),
                'settings' => $voice->settings,
                'sample_url' => route('voices.sample', $voice).'?v='.$voice->updated_at->timestamp,
            ]),
            'languages' => config('voice.languages'),
            'maxSampleMb' => intdiv(config('voice.max_sample_kb'), 1024),
        ]);
    }

    public function store(Request $request, VoiceEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'language' => ['required', Rule::in(array_keys(config('voice.languages')))],
            'sample' => ['required', 'file', 'max:'.config('voice.max_sample_kb'), 'mimetypes:'.implode(',', self::MIMES)],
            'consent' => ['accepted'],
            'clean' => ['boolean'],
        ], [
            'name.required' => 'Donnez un nom à cette voix.',
            'sample.required' => 'Ajoutez ou enregistrez un échantillon audio.',
            'sample.mimetypes' => 'Ce fichier n\'est pas un format audio reconnu (WAV, MP3, M4A, OGG, WebM, FLAC).',
            'sample.max' => 'L\'échantillon est trop volumineux.',
            'consent.accepted' => 'Vous devez confirmer que vous avez le droit d\'utiliser cette voix.',
        ]);

        $file = $request->file('sample');

        // Le nettoyage passe avant tout enregistrement : s'il échoue, rien n'est créé
        $cleaned = null;
        if ($data['clean'] ?? false) {
            $cleaned = $this->clean($engine, $file->getContent(), 'sample.'.($file->extension() ?: 'bin'));
        }

        $path = $file->storeAs('voices', Str::uuid().'.'.($file->extension() ?: 'bin'));

        Voice::create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'language' => $data['language'],
            'sample_path' => $path,
            'sample_mime' => $file->getMimeType(),
            'consent_confirmed_at' => now(),
            'cleaned_path' => $cleaned,
            'use_cleaned' => $cleaned !== null,
        ]);

        return back()->with('success', 'Voix « '.$data['name'].' » ajoutée.');
    }

    /** Active ou désactive la version nettoyée de l'échantillon (créée au besoin). */
    public function cleaning(Request $request, Voice $voice, VoiceEngine $engine): RedirectResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        if ($enabled && ! $voice->cleaned_path) {
            $voice->cleaned_path = $this->clean($engine, Storage::get($voice->sample_path), basename($voice->sample_path));
        }
        $voice->use_cleaned = $enabled;
        $voice->save();

        return back()->with('success', $enabled
            ? 'L\'échantillon nettoyé sera utilisé pour « '.$voice->name.' ».'
            : 'L\'échantillon d\'origine sera utilisé pour « '.$voice->name.' ».');
    }

    /** Enregistre (ou efface) les réglages favoris de la voix. */
    public function settings(Request $request, Voice $voice): RedirectResponse
    {
        $data = $request->validate([
            'settings' => ['present', 'nullable', 'array'],
            'settings.exaggeration' => ['required_with:settings', 'numeric', 'between:0.25,2'],
            'settings.cfg_weight' => ['required_with:settings', 'numeric', 'between:0,1'],
            'settings.temperature' => ['required_with:settings', 'numeric', 'between:0.05,2'],
        ]);

        $voice->update(['settings' => $data['settings'] ? array_map('floatval', $data['settings']) : null]);

        return back()->with('success', $data['settings']
            ? 'Réglages enregistrés pour « '.$voice->name.' ».'
            : 'Réglages favoris effacés pour « '.$voice->name.' ».');
    }

    public function sample(Request $request, Voice $voice): BinaryFileResponse
    {
        $original = $request->query('version') === 'original' || ! $voice->usesCleaned();
        $path = $original ? $voice->sample_path : $voice->cleaned_path;
        abort_unless(Storage::exists($path), 404);

        return response()->file(Storage::path($path), ['Content-Type' => $original ? $voice->sample_mime : 'audio/wav']);
    }

    private function clean(VoiceEngine $engine, string $contents, string $filename): string
    {
        try {
            $wav = $engine->cleanSample($contents, $filename);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['sample' => $e->getMessage()]);
        }

        $path = 'voices/'.Str::uuid().'-propre.wav';
        Storage::put($path, $wav);

        return $path;
    }

    public function destroy(Voice $voice): RedirectResponse
    {
        $voice->delete();

        return back()->with('success', 'Voix supprimée.');
    }
}
