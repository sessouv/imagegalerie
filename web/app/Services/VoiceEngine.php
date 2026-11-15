<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Client HTTP du moteur vocal (service Python). */
class VoiceEngine
{
    /**
     * @param  array{exaggeration?: float, cfg_weight?: float, temperature?: float, seed?: int|null}  $settings
     * @param  array{contents: string, filename: string}|null  $sample  Échantillon de la voix à cloner
     * @return array{audio: string, duration_ms: int}
     */
    public function speech(string $text, string $language, array $settings = [], ?array $sample = null): array
    {
        $request = Http::withToken((string) config('voice.engine_token'))
            ->timeout(config('voice.timeout'))
            ->connectTimeout(10)
            ->asMultipart();

        if ($sample) {
            $request->attach('sample', $sample['contents'], $sample['filename']);
        }

        $fields = array_filter(
            ['text' => $text, 'language' => $language, 'format' => 'mp3'] + $settings,
            fn ($value) => $value !== null,
        );

        try {
            $response = $request->post(config('voice.engine_url').'/v1/speech', $fields);
        } catch (ConnectionException) {
            throw new RuntimeException('Le moteur vocal est injoignable.');
        }

        if ($response->failed()) {
            $detail = $response->json('detail');
            throw new RuntimeException(is_string($detail) ? $detail : 'Le moteur vocal a renvoyé une erreur ('.$response->status().').');
        }

        return [
            'audio' => $response->body(),
            'duration_ms' => (int) $response->header('X-Audio-Duration-Ms'),
        ];
    }

    /** @return array{status: string, driver?: string} */
    public function health(): array
    {
        try {
            $response = Http::timeout(3)->connectTimeout(2)->get(config('voice.engine_url').'/health');

            return $response->successful()
                ? ['status' => $response->json('status', 'ok'), 'driver' => $response->json('driver')]
                : ['status' => 'down'];
        } catch (ConnectionException) {
            return ['status' => 'down'];
        }
    }
}
