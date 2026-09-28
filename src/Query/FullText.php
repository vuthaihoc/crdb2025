<?php

namespace YlsIdeas\CockroachDb\Query;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * The full-text expressions shared by FULLTEXT indexes and whereFullText(),
 * so that a query always matches the expression of its index.
 *
 * CockroachDB has no `tsvector || tsvector` (PostgreSQL's way to search
 * several columns), so the columns are concatenated as text; a NULL column
 * would make the whole document NULL, hence coalesce().
 */
final class FullText
{
    /**
     * The text search configurations of CockroachDB (v23.1 to v26.2).
     */
    public const LANGUAGES = [
        'simple', 'danish', 'dutch', 'english', 'finnish', 'french', 'german', 'hungarian',
        'italian', 'norwegian', 'portuguese', 'russian', 'spanish', 'swedish', 'turkish',
    ];

    /**
     * The language given, else the connection's `fulltext_language`, else english.
     */
    public static function language(Connection $connection, ?string $language): string
    {
        $language = $language ?: ($connection->getConfig('fulltext_language') ?: 'english');

        if (! in_array($language, self::LANGUAGES, true)) {
            throw new InvalidArgumentException(
                "CockroachDB has no text search configuration [{$language}]; use one of: ".implode(', ', self::LANGUAGES).'.'
            );
        }

        return $language;
    }

    /**
     * to_tsvector('language', coalesce(a, '') || ' ' || coalesce(b, '')).
     *
     * @param  list<string>  $wrappedColumns
     */
    public static function document(array $wrappedColumns, string $language): string
    {
        $text = implode(" || ' ' || ", array_map(fn (string $column) => "coalesce({$column}, '')", $wrappedColumns));

        return "to_tsvector('{$language}', {$text})";
    }

    /**
     * The tsquery function of a mode: plainto_tsquery (default),
     * phraseto_tsquery ('phrase') or to_tsquery ('raw', and 'websearch'
     * once translated).
     */
    public static function queryFunction(?string $mode): string
    {
        return match ($mode) {
            'phrase' => 'phraseto_tsquery',
            'raw', 'custom', 'websearch' => 'to_tsquery',
            default => 'plainto_tsquery',
        };
    }

    /**
     * The value to bind for a mode, and the plain words of the search.
     *
     * CockroachDB parses operators even in plainto_tsquery() ("c++" is a
     * syntax error), so only words are kept; 'websearch' (which CockroachDB
     * lacks) becomes to_tsquery() syntax. The words tell whether the search
     * has any lexeme: a search made of stopwords or punctuation is an error
     * on CockroachDB, where PostgreSQL matches nothing.
     *
     * @return array{0: string, 1: string} [words, value]
     */
    public static function prepare(string $value, ?string $mode): array
    {
        if (in_array($mode, ['raw', 'custom'], true)) {
            return [self::words($value), $value];
        }

        if ($mode === 'websearch') {
            return self::websearch($value);
        }

        $words = self::words($value);

        return [$words, $words];
    }

    /**
     * Translate web search syntax: `"a phrase"` (words in sequence), `-word`
     * (excluded), `or` between terms, and every other term required.
     *
     * @return array{0: string, 1: string} [words, to_tsquery value]
     */
    public static function websearch(string $value): array
    {
        preg_match_all('/(-?)"([^"]*)"?|(\S+)/u', $value, $matches, PREG_SET_ORDER);

        $query = '';
        $words = [];
        $operator = null;

        foreach ($matches as $match) {
            if (($match[3] ?? '') !== '' && strtolower($match[3]) === 'or') {
                $operator = $query === '' ? null : ' | ';

                continue;
            }

            $negated = $match[1] === '-' || str_starts_with($match[3] ?? '', '-');
            $termWords = self::words(($match[3] ?? '') !== '' ? $match[3] : $match[2]);

            if ($termWords === '') {
                continue;
            }

            $term = str_replace(' ', ' <-> ', $termWords);
            $term = str_contains($term, ' ') ? "({$term})" : $term;

            $query .= ($query === '' ? '' : ($operator ?? ' & ')).($negated ? '!' : '').$term;
            $operator = null;
            $words[] = $termWords;
        }

        return [implode(' ', $words), $query];
    }

    /**
     * The letters and digits of a search, separated by single spaces.
     */
    public static function words(string $value): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }
}
