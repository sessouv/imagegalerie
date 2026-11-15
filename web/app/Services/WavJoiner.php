<?php

namespace App\Services;

use RuntimeException;

/** Assemble des fichiers WAV (PCM 16 bits mono) bout à bout, avec des silences. */
class WavJoiner
{
    /**
     * @param  list<array{path: string, pause_ms: int}>  $parts  Chemins absolus, pause après chaque partie
     * @return int Durée totale en millisecondes
     */
    public function join(array $parts, string $destination): int
    {
        $out = fopen($destination, 'wb');
        fwrite($out, str_repeat("\0", 44));

        $rate = null;
        $bytes = 0;
        $last = array_key_last($parts);

        foreach ($parts as $index => $part) {
            $in = fopen($part['path'], 'rb');
            [$partRate, $length] = $this->seekToData($in);
            if ($rate !== null && $partRate !== $rate) {
                throw new RuntimeException('Les passages n\'ont pas tous la même fréquence audio.');
            }
            $rate = $partRate;

            $bytes += stream_copy_to_stream($in, $out, $length);
            fclose($in);

            if ($index !== $last) {
                $silence = intdiv($rate * $part['pause_ms'], 1000) * 2;
                fwrite($out, str_repeat("\0", $silence));
                $bytes += $silence;
            }
        }

        if ($rate === null) {
            throw new RuntimeException('Aucun passage à assembler.');
        }

        rewind($out);
        fwrite($out, 'RIFF'.pack('V', 36 + $bytes).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', $bytes));
        fclose($out);

        return (int) round($bytes / 2 / $rate * 1000);
    }

    /**
     * Place le curseur au début des données audio.
     *
     * @param  resource  $in
     * @return array{int, int} Fréquence d'échantillonnage et taille des données en octets
     */
    private function seekToData($in): array
    {
        $header = fread($in, 12);
        if (strlen($header) < 12 || ! str_starts_with($header, 'RIFF') || substr($header, 8) !== 'WAVE') {
            throw new RuntimeException('Fichier audio de passage invalide.');
        }

        $rate = null;
        while (strlen($chunk = fread($in, 8)) === 8) {
            ['id' => $id, 'size' => $size] = unpack('a4id/Vsize', $chunk);
            if ($id === 'fmt ') {
                $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', fread($in, 16));
                if ($fmt['format'] !== 1 || $fmt['channels'] !== 1 || $fmt['bits'] !== 16) {
                    throw new RuntimeException('Format audio de passage inattendu.');
                }
                $rate = $fmt['rate'];
                fseek($in, $size - 16 + ($size & 1), SEEK_CUR);
            } elseif ($id === 'data' && $rate !== null) {
                return [$rate, $size];
            } else {
                fseek($in, $size + ($size & 1), SEEK_CUR);
            }
        }

        throw new RuntimeException('Fichier audio de passage invalide.');
    }
}
