<?php

namespace App\Services;

use App\Models\Pronunciation;

/** Remplace, juste avant la synthèse, les mots du dictionnaire par leur écriture phonétique. */
class PronunciationDictionary
{
    public function apply(string $text, string $language): string
    {
        $entries = Pronunciation::where(fn ($q) => $q->whereNull('language')->orWhere('language', $language))->get();
        if ($entries->isEmpty()) {
            return $text;
        }

        // Une entrée propre à la langue l'emporte sur une entrée « toutes langues »
        $exact = $insensitive = [];
        foreach ($entries->sortBy(fn (Pronunciation $e) => $e->language === null ? 0 : 1) as $entry) {
            if ($entry->case_sensitive) {
                $exact[$entry->term] = $entry->replacement;
            } else {
                $insensitive[mb_strtolower($entry->term)] = $entry->replacement;
            }
        }

        // Les termes les plus longs d'abord : « Le Web Artisan » avant « Web »
        $terms = $entries->pluck('term')->unique()->sortByDesc(fn (string $term) => mb_strlen($term));
        $pattern = '/(?<![\p{L}\p{N}])(?:'.$terms->map(fn (string $term) => preg_quote($term, '/'))->implode('|').')(?![\p{L}\p{N}])/iu';

        // Un seul passage : un remplacement n'est jamais remplacé à son tour
        return preg_replace_callback(
            $pattern,
            fn (array $m) => $exact[$m[0]] ?? $insensitive[mb_strtolower($m[0])] ?? $m[0],
            $text,
        ) ?? $text;
    }
}
