<?php

namespace App\Services;

/** Découpe un texte en passages courts, sans jamais couper au milieu d'un mot. */
class TextSplitter
{
    /**
     * Chaque ligne du texte est un paragraphe. Dans un paragraphe, les phrases
     * sont regroupées tant qu'elles tiennent dans la limite.
     *
     * @return list<array{text: string, ends_paragraph: bool}>
     */
    public function split(string $text, int $limit): array
    {
        $segments = [];

        foreach (preg_split('/\R+/u', $text) as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/u', ' ', $paragraph));
            if ($paragraph === '') {
                continue;
            }

            $current = '';
            foreach ($this->pieces($paragraph, $limit) as $piece) {
                if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($piece) > $limit) {
                    $segments[] = ['text' => $current, 'ends_paragraph' => false];
                    $current = $piece;
                } else {
                    $current = trim($current.' '.$piece);
                }
            }
            $segments[] = ['text' => $current, 'ends_paragraph' => true];
        }

        return $segments;
    }

    /** Phrases, recoupées aux virgules puis aux espaces si elles sont trop longues. */
    private function pieces(string $paragraph, int $limit): iterable
    {
        foreach (preg_split('/(?<=[.!?…;:])\s+/u', $paragraph) as $sentence) {
            if (mb_strlen($sentence) <= $limit) {
                yield $sentence;

                continue;
            }
            foreach (preg_split('/(?<=,)\s+/u', $sentence) as $part) {
                while (mb_strlen($part) > $limit) {
                    $cut = mb_strrpos(mb_substr($part, 0, $limit), ' ') ?: $limit;
                    yield trim(mb_substr($part, 0, $cut));
                    $part = trim(mb_substr($part, $cut));
                }
                if ($part !== '') {
                    yield $part;
                }
            }
        }
    }
}
