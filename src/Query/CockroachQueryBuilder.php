<?php

namespace YlsIdeas\CockroachDb\Query;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

/**
 * Query builder with CockroachDB's historical reads (AS OF SYSTEM TIME),
 * full-text relevance and trigram (fuzzy) search.
 *
 * @property CockroachGrammar $grammar
 */
class CockroachQueryBuilder extends Builder
{
    /**
     * The AS OF SYSTEM TIME expression of the query, compiled after its joins.
     */
    public ?string $asOfSystemTime = null;

    /**
     * Read the data as it was at a point in the past:
     * asOfSystemTime('-10s'), asOfSystemTime(now()->subMinute()).
     *
     * The read takes no locks and never conflicts with writes. CockroachDB only
     * accepts it on a top-level SELECT (not in subqueries), and it is left out
     * inside a transaction, where CockroachDB rejects it.
     *
     * @return $this
     */
    public function asOfSystemTime(DateTimeInterface|string $time): static
    {
        if ($time instanceof DateTimeInterface) {
            $time = $time->format('Y-m-d H:i:s.uP');
        } elseif (! preg_match('/^-?\d+(\.\d+)?(us|µs|ms|s|m|h)(\d+(\.\d+)?(us|µs|ms|s|m|h))*$|^\d{4}-\d{2}-\d{2}/', $time)) {
            throw new InvalidArgumentException("Invalid AS OF SYSTEM TIME value [{$time}]: use an interval such as '-10s' or a timestamp.");
        }

        $this->asOfSystemTime = "'".str_replace("'", "''", $time)."'";

        return $this;
    }

    /**
     * A follower read: data about 4.8 seconds old, which any replica can
     * serve (the nearest one in a multi-node cluster), with no contention
     * with writes. Suited to dashboards and reports.
     *
     * @return $this
     */
    public function followerRead(): static
    {
        $this->asOfSystemTime = 'follower_read_timestamp()';

        return $this;
    }

    /**
     * Read at the current time again.
     *
     * @return $this
     */
    public function withoutHistoricalRead(): static
    {
        $this->asOfSystemTime = null;

        return $this;
    }

    /**
     * Add a full-text "where" clause: the expression of `$table->fullText()`
     * (see FullText), modes 'phrase', 'raw' and 'websearch' (translated, as
     * CockroachDB lacks websearch_to_tsquery). A search without any lexeme
     * (stopwords, punctuation) matches nothing.
     *
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function whereFullText($columns, $value, array $options = [], $boolean = 'and')
    {
        [$words, $value] = FullText::prepare((string) $value, $options['mode'] ?? null);

        parent::whereFullText($columns, $value, $options + ['guard' => true], $boolean);

        // The words come first in the SQL (see CockroachGrammar::compileFullTextMatch).
        array_splice($this->bindings['where'], -1, 0, [$words]);

        return $this;
    }

    /**
     * Add the full-text relevance (ts_rank) to the select list.
     *
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function selectFullTextRelevance(string|array $columns, string $value, string $as = 'relevance', array $options = []): static
    {
        $this->addBinding(FullText::prepare($value, $options['mode'] ?? null), 'select');

        return $this->addSelect(new Expression(
            $this->grammar->compileFullTextRank($this, $columns, $options).' as '.$this->grammar->wrap($as)
        ));
    }

    /**
     * Order the query by full-text relevance (most relevant first).
     *
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function orderByFullTextRelevance(string|array $columns, string $value, array $options = [], string $direction = 'desc'): static
    {
        $sql = $this->grammar->compileFullTextRank($this, $columns, $options);

        return $this->orderByRaw($sql.' '.$this->direction($direction), FullText::prepare($value, $options['mode'] ?? null));
    }

    /**
     * Search the full-text index and order the matches by relevance.
     *
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function searchFullText(string|array $columns, string $value, array $options = []): static
    {
        return $this->whereFullText($columns, $value, $options)->orderByFullTextRelevance($columns, $value, $options);
    }

    /**
     * Rows whose column starts with a value, ignoring case. With
     * `$unaccent`, accents are ignored too: "chao" finds "chào" (index
     * `unaccent(lower(column)) gin_trgm_ops`).
     *
     * @return $this
     */
    public function whereStartsWith(string $column, string $value, bool $unaccent = false, string $boolean = 'and'): static
    {
        return $this->whereLikePattern($column, self::escapeLike($value).'%', $unaccent, $boolean);
    }

    /**
     * Rows whose column contains a value, ignoring case (and accents with
     * `$unaccent`). A trigram index (gin_trgm_ops) serves it.
     *
     * @return $this
     */
    public function whereContains(string $column, string $value, bool $unaccent = false, string $boolean = 'and'): static
    {
        return $this->whereLikePattern($column, '%'.self::escapeLike($value).'%', $unaccent, $boolean);
    }

    /**
     * Rows similar to a value (trigram similarity, typo tolerant), served by
     * a trigram index. The `%` operator uses the session's
     * `pg_trgm.similarity_threshold` (0.3 by default; set it with the
     * connection's `variables` option); `$threshold` adds a stricter minimum.
     *
     * @return $this
     */
    public function whereSimilar(string $column, string $value, ?float $threshold = null, bool $unaccent = false, string $boolean = 'and'): static
    {
        [$expression, $placeholder] = $this->trigramOperands($column, $unaccent);

        if ($threshold === null) {
            return $this->whereRaw("{$expression} % {$placeholder}", [$value], $boolean);
        }

        return $this->whereRaw(
            "({$expression} % {$placeholder} and similarity({$expression}, {$placeholder}) >= ?)",
            [$value, $value, $threshold],
            $boolean
        );
    }

    /**
     * Add the trigram similarity (0 to 1) to the select list.
     *
     * @return $this
     */
    public function selectSimilarity(string $column, string $value, string $as = 'similarity', bool $unaccent = false): static
    {
        [$expression, $placeholder] = $this->trigramOperands($column, $unaccent);

        return $this->selectRaw("similarity({$expression}, {$placeholder}) as {$this->grammar->wrap($as)}", [$value]);
    }

    /**
     * Order by trigram similarity (most similar first).
     *
     * @return $this
     */
    public function orderBySimilarity(string $column, string $value, string $direction = 'desc', bool $unaccent = false): static
    {
        [$expression, $placeholder] = $this->trigramOperands($column, $unaccent);

        return $this->orderByRaw("similarity({$expression}, {$placeholder}) ".$this->direction($direction), [$value]);
    }

    /**
     * Suggestions for a search box: values starting with the search; from 3
     * characters also values containing it or similar to it. Values starting
     * with the search first, then the most similar, then the shortest.
     * An empty search matches nothing.
     *
     * @return $this
     */
    public function suggest(string $column, string $value, bool $unaccent = false): static
    {
        $value = trim($value);

        if ($value === '') {
            return $this->whereRaw('false');
        }

        [$expression, $placeholder] = $this->trigramOperands($column, $unaccent);
        $prefix = self::escapeLike($value).'%';
        $like = $unaccent ? "{$expression} like {$placeholder}" : "{$expression} ilike ?";

        if (mb_strlen($value) < 3) {
            $this->whereStartsWith($column, $value, $unaccent);
        } else {
            $this->where(fn (self $query) => $query
                ->whereContains($column, $value, $unaccent)
                ->whereSimilar($column, $value, null, $unaccent, 'or'));

            $this->orderByRaw("{$like} desc", [$prefix]);
            $this->orderBySimilarity($column, $value, 'desc', $unaccent);
        }

        return $this->orderByRaw("length({$expression})")->orderBy($column);
    }

    /**
     * Escape the LIKE wildcards of a value (the escape character is "\\").
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return $this
     */
    protected function whereLikePattern(string $column, string $pattern, bool $unaccent, string $boolean): static
    {
        [$expression, $placeholder] = $this->trigramOperands($column, $unaccent);

        return $unaccent
            ? $this->whereRaw("{$expression} like {$placeholder}", [$pattern], $boolean)
            : $this->whereRaw("{$expression} ilike ?", [$pattern], $boolean);
    }

    /**
     * The column and value expressions: `unaccent(lower(...))` with
     * `$unaccent`, matching `trigramIndex(..., unaccent: true)`.
     *
     * @return array{0: string, 1: string}
     */
    protected function trigramOperands(string $column, bool $unaccent): array
    {
        $wrapped = $this->grammar->wrap($column);

        return $unaccent ? ["unaccent(lower({$wrapped}))", 'unaccent(lower(?))'] : [$wrapped, '?'];
    }

    protected function direction(string $direction): string
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }

        return $direction;
    }
}
