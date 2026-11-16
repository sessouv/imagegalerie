<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\GenerationSegment;
use App\Models\Project;
use App\Services\GenerationCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'project' => ['nullable', 'string', 'max:20'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $project = $filters['project'] ?? null;

        $generations = Generation::withProgress()
            ->when($search !== '', function ($query) use ($search) {
                // « ! » sert de caractère d'échappement : % et _ sont cherchés tels quels
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $query->where(fn ($q) => $q->whereRaw("title like ? escape '!'", [$like])
                    ->orWhereRaw("text like ? escape '!'", [$like])
                    ->orWhereRaw("voice_name like ? escape '!'", [$like]));
            })
            ->when($project === 'none', fn ($q) => $q->whereNull('project_id'))
            ->when(ctype_digit((string) $project), fn ($q) => $q->where('project_id', (int) $project))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Generation $g) => $g->present());

        return Inertia::render('History', [
            'generations' => $generations,
            'projects' => fn () => Project::withCount('generations')->orderBy('name')->get(['id', 'name']),
            'unfiled' => fn () => Generation::whereNull('project_id')->count(),
            'filters' => ['q' => $search, 'project' => $project],
        ]);
    }

    public function store(Request $request, GenerationCreator $creator): RedirectResponse
    {
        $creator->create($request->user(), $request->validate($creator->rules(), $creator->messages()));

        return back();
    }

    /** Renomme l'audio ou le range dans un projet. */
    public function update(Request $request, Generation $generation): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'exists:projects,id'],
        ]);

        if (array_key_exists('title', $data)) {
            $data['title'] = filled($data['title']) ? trim($data['title']) : null;
        }
        $generation->update($data);

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
