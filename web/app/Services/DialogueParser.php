<?php

namespace App\Services;

use App\Models\Voice;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Lit un dialogue où chaque réplique commence par le nom d'une voix entre crochets :
 *
 *   [Camille] Bonjour, tu vas bien ?
 *   [Paul] Très bien, merci.
 *
 * Une ligne sans crochets garde la voix de la ligne précédente.
 */
class DialogueParser
{
    /**
     * @param  Collection<int, Voice>  $voices
     * @param  Voice|null  $narrator  Voix des lignes placées avant la première balise
     * @return list<array{voice: Voice|null, text: string}>
     */
    public function parse(string $text, Collection $voices, ?Voice $narrator): array
    {
        $byName = $voices->keyBy(fn (Voice $voice) => $this->key($voice->name));
        $current = $narrator;
        $lines = [];

        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match('/^\s*\[([^\]\n]{1,80})\]\s*(.*)$/u', $line, $m)) {
                $voice = $byName->get($this->key($m[1]));
                if (! $voice) {
                    throw new InvalidArgumentException('Voix inconnue dans le dialogue : « '.trim($m[1]).' ».');
                }
                $current = $voice;
                $line = $m[2];
            }
            if (trim($line) !== '') {
                $lines[] = ['voice' => $current, 'text' => trim($line)];
            }
        }

        return $lines;
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }
}
