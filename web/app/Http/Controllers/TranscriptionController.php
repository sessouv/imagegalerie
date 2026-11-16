<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\Transcription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TranscriptionController extends Controller
{
    public const MESSAGES = [
        'file.required' => 'Choisissez un fichier audio ou vidéo.',
        'file.mimetypes' => 'Ce fichier n\'est ni un audio ni une vidéo.',
        'file.max' => 'Le fichier est trop volumineux.',
        'file.uploaded' => 'L\'envoi a échoué : le fichier dépasse sans doute la taille autorisée par PHP (upload_max_filesize).',
    ];

    public static function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('voice.max_transcribe_kb'), 'mimetypes:audio/*,video/*,application/ogg'],
            'language' => ['nullable', Rule::in(array_keys(config('voice.languages')))],
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Transcriptions', [
            'transcriptions' => Transcription::with('user:id,name')->latest('id')->limit(50)->get()->map->present(),
            'languages' => config('voice.languages'),
            'maxMb' => intdiv(config('voice.max_transcribe_kb'), 1024),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(self::rules(), self::MESSAGES);

        Transcription::start($request->user(), $request->file('file'), $data['language'] ?? null);

        return back();
    }

    public function retry(Transcription $transcription): RedirectResponse
    {
        abort_unless($transcription->status === Generation::FAILED, 409);

        $transcription->enqueue();

        return back();
    }

    /** Télécharge le texte (.txt) ou les sous-titres (.srt). */
    public function download(Transcription $transcription, string $format): HttpResponse
    {
        abort_unless($transcription->status === Generation::COMPLETED && in_array($format, ['txt', 'srt'], true), 404);

        $name = (Str::slug(pathinfo($transcription->name, PATHINFO_FILENAME)) ?: 'transcription-'.$transcription->id).'.'.$format;
        $body = $format === 'srt' ? $transcription->toSrt() : $transcription->text."\n";

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function destroy(Transcription $transcription): RedirectResponse
    {
        $transcription->delete();

        return back();
    }
}
