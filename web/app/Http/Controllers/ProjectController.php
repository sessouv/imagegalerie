<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $project = Project::create($this->validated($request) + ['user_id' => $request->user()->id]);

        return redirect()->route('generations.index', ['project' => $project->id]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $project->update($this->validated($request));

        return back();
    }

    /** Les audios du projet sont conservés : ils redeviennent « sans projet ». */
    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();

        return redirect()->route('generations.index');
    }

    private function validated(Request $request): array
    {
        return $request->validate(
            ['name' => ['required', 'string', 'max:80']],
            ['name.required' => 'Donnez un nom au projet.'],
        );
    }
}
