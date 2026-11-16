<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voice;
use Illuminate\Http\JsonResponse;

class VoiceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Voice::orderBy('name')->get(['id', 'name', 'language', 'description']),
        ]);
    }
}
