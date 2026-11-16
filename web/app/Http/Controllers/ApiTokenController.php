<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Api', [
            'tokens' => ApiToken::with('user:id,name')->latest('id')->get()->map(fn (ApiToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'hint' => $token->hint,
                'author' => $token->user?->name,
                'created_at' => $token->created_at->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ]),
            // Affichée une seule fois, juste après sa création
            'newToken' => $request->session()->get('new_token'),
            'baseUrl' => url('/api/v1'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['name' => ['required', 'string', 'max:80']],
            ['name.required' => 'Indiquez à quoi servira cette clé.'],
        );

        [, $plain] = ApiToken::issue($request->user(), trim($data['name']));

        return back()->with('new_token', $plain);
    }

    public function destroy(ApiToken $token): RedirectResponse
    {
        $token->delete();

        return back()->with('success', 'Clé révoquée : elle ne fonctionne plus.');
    }
}
