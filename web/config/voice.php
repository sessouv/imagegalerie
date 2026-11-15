<?php

return [
    // Adresse du moteur vocal (service Python du dossier engine/)
    'engine_url' => rtrim(env('VOICE_ENGINE_URL', 'http://127.0.0.1:8100'), '/'),
    'engine_token' => env('VOICE_ENGINE_TOKEN'),

    // Durée maximale accordée à la génération d'un seul passage, en secondes.
    // Doit rester inférieure à DB_QUEUE_RETRY_AFTER (660 par défaut).
    'timeout' => (int) env('VOICE_ENGINE_TIMEOUT', 600),

    'max_chars' => (int) env('VOICE_MAX_CHARS', 100000),
    'max_sample_kb' => (int) env('VOICE_MAX_SAMPLE_KB', 20480),

    // Longueur maximale d'un passage : au-delà, le modèle perd en stabilité
    'segment_chars' => (int) env('VOICE_SEGMENT_CHARS', 280),

    // Silences insérés à l'assemblage, en millisecondes
    'pause_ms' => (int) env('VOICE_PAUSE_MS', 250),
    'paragraph_pause_ms' => (int) env('VOICE_PARAGRAPH_PAUSE_MS', 650),

    'languages' => [
        'fr' => 'Français',
        'en' => 'Anglais',
    ],
];
