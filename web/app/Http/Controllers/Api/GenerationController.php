<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\GenerationCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GenerationController extends Controller
{
    /** La génération est asynchrone : interroger ensuite show() jusqu'au statut « completed ». */
    public function store(Request $request, GenerationCreator $creator): JsonResponse
    {
        $generation = $creator->create($request->user(), $request->validate($creator->rules(), $creator->messages()));

        return response()->json(['data' => $this->present($generation)], 202);
    }

    public function show(Generation $generation): JsonResponse
    {
        return response()->json(['data' => $this->present($generation)]);
    }

    public function audio(Request $request, Generation $generation): BinaryFileResponse
    {
        $wav = $request->query('format') === 'wav';
        $path = $wav ? $generation->wav_path : $generation->audio_path;
        abort_unless($generation->status === Generation::COMPLETED && $path && Storage::exists($path), 404, 'Audio indisponible.');

        return response()->download(
            Storage::path($path),
            $generation->filename().($wav ? '.wav' : '.mp3'),
            ['Content-Type' => $wav ? 'audio/wav' : 'audio/mpeg'],
        );
    }

    public function destroy(Generation $generation): JsonResponse
    {
        $generation->delete();

        return response()->json(null, 204);
    }

    private function present(Generation $generation): array
    {
        $done = $generation->status === Generation::COMPLETED;
        $audio = route('api.generations.audio', $generation);

        return [
            'id' => $generation->id,
            'status' => $generation->status,
            'title' => $generation->title,
            'language' => $generation->language,
            'voice' => $generation->voice_name ?? 'Voix par défaut',
            'passages_done' => $generation->segments()->where('status', Generation::COMPLETED)->count(),
            'passages_total' => $generation->segments()->count(),
            'duration_ms' => $done ? $generation->duration_ms : null,
            'error' => $generation->error,
            'audio_url' => $done ? $audio : null,
            'wav_url' => $done && $generation->wav_path ? $audio.'?format=wav' : null,
            'created_at' => $generation->created_at->toIso8601String(),
        ];
    }
}
