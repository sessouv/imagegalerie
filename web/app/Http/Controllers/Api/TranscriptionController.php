<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TranscriptionController as WebController;
use App\Models\Generation;
use App\Models\Transcription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranscriptionController extends Controller
{
    /** La transcription est asynchrone : interroger ensuite show() jusqu'au statut « completed ». */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(WebController::rules(), WebController::MESSAGES);

        $transcription = Transcription::start($request->user(), $request->file('file'), $data['language'] ?? null);

        return response()->json(['data' => $this->present($transcription)], 202);
    }

    public function show(Transcription $transcription): JsonResponse
    {
        return response()->json(['data' => $this->present($transcription)]);
    }

    public function destroy(Transcription $transcription): JsonResponse
    {
        $transcription->delete();

        return response()->json(null, 204);
    }

    private function present(Transcription $transcription): array
    {
        $done = $transcription->status === Generation::COMPLETED;

        return [
            'id' => $transcription->id,
            'status' => $transcription->status,
            'progress' => $transcription->progress,
            'name' => $transcription->name,
            'language' => $transcription->detected_language ?? $transcription->language,
            'duration_ms' => $transcription->duration_ms,
            'text' => $done ? $transcription->text : null,
            'segments' => $done ? $transcription->segments : null,
            'error' => $transcription->error,
            'created_at' => $transcription->created_at->toIso8601String(),
        ];
    }
}
