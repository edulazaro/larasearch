<?php

namespace EduLazaro\Larasearch\Support;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * Adds "every word of the term, in any order" to a query on a normalized text column.
 *
 * On MySQL, a word goes through the FULLTEXT index when the index can find it: three
 * characters or more (innodb_ft_min_token_size), letters and digits only (an "@" or a "."
 * are operators or separators to MySQL), and not one of InnoDB's stopwords, which the index
 * never stores. Such words match from their start: "sitg" finds "sitges". Everything else,
 * and every word on SQLite or PostgreSQL, goes through LIKE, which matches anywhere and has
 * its wildcards escaped: "50%" means fifty per cent, not "50 and anything".
 */
final class Matcher
{
    /**
     * InnoDB's default stopword list: required in a boolean search, any of them returns nothing.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'about', 'are', 'com', 'for', 'from', 'how', 'that', 'the', 'this', 'was', 'what',
        'when', 'where', 'who', 'will', 'with', 'und', 'www',
    ];

    /**
     * @param  BuilderContract  $query
     * @param  string  $column  Qualified when the query may join (`projects.search_text`).
     * @param  string|null  $term
     * @return BuilderContract
     */
    public static function apply(BuilderContract $query, string $column, ?string $term): BuilderContract
    {
        $words = Text::words($term);

        if ($words === []) {
            return $query;
        }

        $fullText = $query->getConnection()->getDriverName() === 'mysql'
            ? array_values(array_filter($words, fn (string $word) => self::indexable($word)))
            : [];

        if ($fullText !== []) {
            $query->whereRaw(
                "match({$column}) against(? in boolean mode)",
                [implode(' ', array_map(fn (string $word) => "+{$word}*", $fullText))],
            );
        }

        foreach (array_diff($words, $fullText) as $word) {
            $query->whereRaw("{$column} like ? escape '!'", ['%'.self::escape($word).'%']);
        }

        return $query;
    }

    /**
     * Whether MySQL's FULLTEXT index can find the word.
     *
     * @param  string  $word
     * @return bool
     */
    public static function indexable(string $word): bool
    {
        return strlen($word) >= 3
            && ctype_alnum($word)
            && ! in_array($word, self::STOPWORDS, true);
    }

    /**
     * LIKE's wildcards, and the escape character itself, taken literally. "!" rather than a
     * backslash: it means the same on MySQL, SQLite and PostgreSQL without double escaping.
     *
     * @param  string  $word
     * @return string
     */
    private static function escape(string $word): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word);
    }
}
