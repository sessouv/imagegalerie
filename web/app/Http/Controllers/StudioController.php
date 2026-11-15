<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\Voice;
use App\Services\VoiceEngine;
use Inertia\Inertia;
use Inertia\Response;

class StudioController extends Controller
{
    public function __invoke(VoiceEngine $engine): Response
    {
        return Inertia::render('Studio', [
            'voices' => fn () => Voice::orderBy('name')->get(['id', 'name', 'language']),
            'languages' => config('voice.languages'),
            'maxChars' => config('voice.max_chars'),
            'generations' => fn () => Generation::withProgress()->latest('id')->limit(8)->get()
                ->map->present(),
            'engine' => fn () => $engine->health(),
        ]);
    }
}
