<?php

return [
    // Adresse du moteur vocal (service Python du dossier engine/)
    'engine_url' => rtrim(env('VOICE_ENGINE_URL', 'http://127.0.0.1:8100'), '/'),
    'engine_token' => env('VOICE_ENGINE_TOKEN'),

    // Sur CPU, une génération peut durer plusieurs minutes
    'timeout' => (int) env('VOICE_ENGINE_TIMEOUT', 900),

    'max_chars' => (int) env('VOICE_MAX_CHARS', 5000),
    'max_sample_kb' => (int) env('VOICE_MAX_SAMPLE_KB', 20480),

    'languages' => [
        'fr' => 'Français',
        'en' => 'Anglais',
    ],
];
