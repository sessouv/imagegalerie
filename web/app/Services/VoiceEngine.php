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
     * @return array{audio: string, duration_ms: int} Audio au format WAV
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
            ['text' => $text, 'language' => $language, 'format' => 'wav'] + $settings,
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

    /**
     * Nettoie un échantillon de voix (bruit de fond, silences, volume).
     *
     * @return string Échantillon nettoyé, au format WAV
     */
    public function cleanSample(string $contents, string $filename): string
    {
        try {
            $response = Http::withToken((string) config('voice.engine_token'))
                ->timeout(120)
                ->connectTimeout(10)
                ->attach('sample', $contents, $filename)
                ->post(config('voice.engine_url').'/v1/samples/clean');
        } catch (ConnectionException) {
            throw new RuntimeException('Le moteur vocal est injoignable : démarrez-le, ou décochez le nettoyage.');
        }

        if ($response->failed()) {
            $detail = $response->json('detail');
            throw new RuntimeException(is_string($detail) ? $detail : 'Le nettoyage de l\'échantillon a échoué ('.$response->status().').');
        }

        return $response->body();
    }

    /**
     * Produit l'audio final (MP3 ou WAV) à partir d'un fichier WAV, sans le charger en mémoire.
     *
     * @param  bool  $normalize  Ramène le volume à un niveau constant d'un fichier à l'autre
     */
    public function encode(string $wavPath, string $destination, string $format, bool $normalize = false): void
    {
        $source = fopen($wavPath, 'rb');

        try {
            $response = Http::withToken((string) config('voice.engine_token'))
                ->timeout(config('voice.timeout'))
                ->connectTimeout(10)
                ->sink($destination)
                ->attach('audio_file', $source, 'audio.wav')
                ->post(config('voice.engine_url').'/v1/encode', [
                    'format' => $format,
                    'normalize' => $normalize ? 'true' : 'false',
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Le moteur vocal est injoignable.');
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }

        if ($response->failed()) {
            @unlink($destination);
            throw new RuntimeException('L\'assemblage de l\'audio a échoué ('.$response->status().').');
        }
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
