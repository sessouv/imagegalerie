<?php

namespace App\Http\Controllers;

use App\Models\Pronunciation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PronunciationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Pronunciations', [
            'entries' => Pronunciation::orderBy('term')->get(['id', 'term', 'replacement', 'language', 'case_sensitive']),
            'languages' => config('voice.languages'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Pronunciation::create($this->validated($request) + ['user_id' => $request->user()->id]);

        return back();
    }

    public function update(Request $request, Pronunciation $pronunciation): RedirectResponse
    {
        $pronunciation->update($this->validated($request, $pronunciation));

        return back();
    }

    public function destroy(Pronunciation $pronunciation): RedirectResponse
    {
        $pronunciation->delete();

        return back();
    }

    private function validated(Request $request, ?Pronunciation $current = null): array
    {
        $data = $request->validate([
            'term' => ['required', 'string', 'max:100'],
            'replacement' => ['required', 'string', 'max:200'],
            'language' => ['nullable', Rule::in(array_keys(config('voice.languages')))],
            'case_sensitive' => ['boolean'],
        ], [
            'term.required' => 'Indiquez le mot ou l\'expression.',
            'replacement.required' => 'Indiquez comment le lire.',
        ]);

        $data['term'] = trim(preg_replace('/\s+/u', ' ', $data['term']));
        $data['replacement'] = trim(preg_replace('/\s+/u', ' ', $data['replacement']));
        $data['language'] ??= null;
        $data['case_sensitive'] ??= false;

        $duplicate = Pronunciation::where('language', $data['language'])
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->get()
            ->contains(fn (Pronunciation $p) => mb_strtolower($p->term) === mb_strtolower($data['term']));
        if ($duplicate) {
            throw ValidationException::withMessages(['term' => 'Ce mot est déjà dans le dictionnaire pour cette langue.']);
        }

        return $data;
    }
}
