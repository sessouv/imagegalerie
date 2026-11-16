<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Crée une génération et la met en file : point d'entrée commun à l'interface et à l'API. */
class GenerationCreator
{
    public function __construct(private TextSplitter $splitter, private DialogueParser $dialogue) {}

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:120'],
            'text' => ['required', 'string', 'max:'.config('voice.max_chars')],
            'language' => ['required', Rule::in(array_keys(config('voice.languages')))],
            'voice_id' => ['nullable', 'integer', 'exists:voices,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'dialogue' => ['boolean'],
            'exaggeration' => ['numeric', 'between:0.25,2'],
            'cfg_weight' => ['numeric', 'between:0,1'],
            'temperature' => ['numeric', 'between:0.05,2'],
            'seed' => ['nullable', 'integer', 'between:0,2147483647'],
            'pause_ms' => ['integer', 'between:0,3000'],
            'paragraph_pause_ms' => ['integer', 'between:0,5000'],
            'normalize' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'text.required' => 'Saisissez le texte à lire.',
            'text.max' => 'Le texte dépasse :max caractères.',
        ];
    }

    /** @param  array<string, mixed>  $data  Données validées avec rules() */
    public function create(User $user, array $data): Generation
    {
        $voice = isset($data['voice_id']) ? Voice::find($data['voice_id']) : null;
        $isDialogue = (bool) ($data['dialogue'] ?? false);

        // Un texte simple est un dialogue à une seule voix
        try {
            $lines = $isDialogue
                ? $this->dialogue->parse($data['text'], Voice::all(), $voice)
                : [['voice' => $voice, 'text' => $data['text']]];
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['text' => $e->getMessage()]);
        }

        $segments = [];
        foreach ($lines as $line) {
            foreach ($this->splitter->split($line['text'], config('voice.segment_chars')) as $segment) {
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

        $generation = DB::transaction(function () use ($user, $data, $voice, $segments, $isDialogue, $speakers) {
            $generation = Generation::create([
                'user_id' => $user->id,
                'voice_id' => $voice?->id,
                'project_id' => $data['project_id'] ?? null,
                'voice_name' => $speakers->count() > 1 ? Str::limit('Dialogue : '.$speakers->implode(', '), 250) : $segments[0]['voice_name'],
                'title' => filled($data['title'] ?? null) ? trim($data['title']) : null,
                'text' => trim($data['text']),
                'language' => $data['language'],
                'settings' => [
                    'exaggeration' => (float) ($data['exaggeration'] ?? 0.5),
                    'cfg_weight' => (float) ($data['cfg_weight'] ?? 0.5),
                    'temperature' => (float) ($data['temperature'] ?? 0.8),
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

        return $generation;
    }
}
