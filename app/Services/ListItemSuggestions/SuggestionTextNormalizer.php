<?php

namespace App\Services\ListItemSuggestions;

use Illuminate\Support\Str;

class SuggestionTextNormalizer
{
    private const CYRILLIC_TO_LATIN = [
        'Р°' => 'a',
        'Р±' => 'b',
        'РІ' => 'v',
        'Рі' => 'g',
        'Рґ' => 'd',
        'Рµ' => 'e',
        'С‘' => 'e',
        'Р¶' => 'zh',
        'Р·' => 'z',
        'Рё' => 'i',
        'Р№' => 'y',
        'Рє' => 'k',
        'Р»' => 'l',
        'Рј' => 'm',
        'РЅ' => 'n',
        'Рѕ' => 'o',
        'Рї' => 'p',
        'СЂ' => 'r',
        'СЃ' => 's',
        'С‚' => 't',
        'Сѓ' => 'u',
        'С„' => 'f',
        'С…' => 'h',
        'С†' => 'ts',
        'С‡' => 'ch',
        'С€' => 'sh',
        'С‰' => 'shch',
        'С‹' => 'y',
        'СЌ' => 'e',
        'СЋ' => 'yu',
        'СЏ' => 'ya',
        'СЊ' => '',
        'СЉ' => '',
    ];

    public function normalizeSuggestionKey(string $value): string
    {
        return $this->normalizeText($value);
    }

    public function normalizeText(string $value): string
    {
        $normalized = Str::of($this->transliterateToLatin($value))
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            $normalized = Str::of($value)
                ->lower()
                ->replaceMatches('/[^\p{L}\p{N}\s]+/u', ' ')
                ->squish()
                ->value();

            if ($normalized === '') {
                return '';
            }
        }

        $tokens = array_values(array_filter(explode(' ', $normalized), static fn (string $token): bool => $token !== ''));
        if ($tokens === []) {
            return '';
        }

        $tokens = array_map([$this, 'normalizeToken'], $tokens);
        $tokens = array_values(array_unique(array_filter($tokens, static fn (string $token): bool => $token !== '')));

        sort($tokens);

        return implode(' ', $tokens);
    }

    public function isFuzzyMatch(string $first, string $second): bool
    {
        if ($first === $second) {
            return true;
        }

        $maxLength = max(strlen($first), strlen($second));
        $minLength = min(strlen($first), strlen($second));

        if ($maxLength === 0) {
            return false;
        }

        if ($maxLength >= 5 && (str_contains($first, $second) || str_contains($second, $first))) {
            if ($minLength / $maxLength >= 0.75) {
                return true;
            }
        }

        $distance = levenshtein($first, $second);
        $distanceThreshold = max(1, (int) floor($maxLength * 0.22));
        if ($distance <= $distanceThreshold) {
            return true;
        }

        similar_text($first, $second, $percent);
        if ($percent >= 78.0) {
            return true;
        }

        $firstTokens = array_values(array_filter(explode(' ', $first), static fn (string $token): bool => $token !== ''));
        $secondTokens = array_values(array_filter(explode(' ', $second), static fn (string $token): bool => $token !== ''));

        if ($firstTokens === [] || $secondTokens === []) {
            return false;
        }

        $intersection = count(array_intersect($firstTokens, $secondTokens));
        $union = count(array_unique([...$firstTokens, ...$secondTokens]));

        return $union > 0 && ($intersection / $union) >= 0.6;
    }

    private function normalizeToken(string $token): string
    {
        if (! preg_match('/^[a-z0-9]+$/', $token)) {
            return $token;
        }

        $length = strlen($token);

        if ($length <= 3) {
            return $token;
        }

        if ($length > 4 && str_ends_with($token, 'ies')) {
            return substr($token, 0, -3).'y';
        }

        if ($length > 4 && str_ends_with($token, 'es')) {
            return substr($token, 0, -2);
        }

        if ($length > 4 && str_ends_with($token, 's')) {
            return substr($token, 0, -1);
        }

        return $token;
    }

    private function transliterateToLatin(string $value): string
    {
        $lower = mb_strtolower($value, 'UTF-8');

        return strtr($lower, self::CYRILLIC_TO_LATIN);
    }
}
