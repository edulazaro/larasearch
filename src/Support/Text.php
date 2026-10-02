<?php

namespace EduLazaro\Larasearch\Support;

use Illuminate\Support\Str;

/**
 * How text is prepared, the same when it is stored and when it is searched: lower case,
 * without accents, with single spaces. "Hotel  Arts, Barcelona" and "hotel arts barcelona"
 * are the same text, and "camion" finds "camión".
 */
final class Text
{
    /**
     * @param  string|null  $text
     * @return string
     */
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Accents off before lowering: ascii() maps "Á" to "A" and "ñ" to "n".
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($text))));
    }

    /**
     * The words a search is made of: normalized, without repeats.
     *
     * @param  string|null  $term
     * @return list<string>
     */
    public static function words(?string $term): array
    {
        $normalized = self::normalize($term);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }
}
