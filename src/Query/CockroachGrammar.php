<?php

namespace YlsIdeas\CockroachDb\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use YlsIdeas\CockroachDb\Exceptions\FeatureNotSupportedException;

class CockroachGrammar extends PostgresGrammar
{
    /**
     * AS OF SYSTEM TIME follows the FROM clause and its joins.
     *
     * @var string[]
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'indexHint',
        'joins',
        'asOfSystemTime',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'lock',
    ];

    /**
     * Compile the AS OF SYSTEM TIME clause of a historical read. CockroachDB
     * rejects it inside a transaction ("inconsistent AS OF SYSTEM TIME
     * timestamp"), so there the query reads current data instead.
     */
    protected function compileAsOfSystemTime(Builder $query, string $expression): string
    {
        if ($query->getConnection()->transactionLevel() > 0) {
            return '';
        }

        return 'as of system time '.$expression;
    }

    /**
     * Compile an update statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileUpdate(Builder $query, array $values): string
    {
        if (! empty($query->joins)) {
            $statement = parent::compileUpdateFrom($query, $values);
        } else {
            $statement = Grammar::compileUpdate($query, $values);
        }

        if ($query->orders) {
            $statement .= ' '.$this->compileOrders($query, $query->orders);
        }
        if ($query->limit) {
            $statement .= ' '.$this->compileLimit($query, $query->limit);
        }

        return $statement;
    }

    /**
     * Compile a delete statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileDelete(Builder $query)
    {
        $table = $this->wrapTable($query->from);

        $where = $this->compileWheres($query);

        if (! empty($query->joins)) {
            throw new FeatureNotSupportedException(
                'Joins for deletions are not supported by CockroachDB, consider using a where in sub-query instead.'
            );
        }

        $statement = "delete from {$table} {$where}";
        if ($query->orders) {
            $statement .= ' '.$this->compileOrders($query, $query->orders);
        }
        if ($query->limit) {
            $statement .= ' '.$this->compileLimit($query, $query->limit);
        }

        return trim($statement);
    }

    /**
     * Compile a full-text "where" clause with the expression of the FULLTEXT
     * index (see FullText). With `vector` => true, the column is a tsvector.
     *
     * CockroachQueryBuilder binds the words of the search first: a search
     * without any lexeme (stopwords, punctuation) then matches nothing
     * instead of failing.
     */
    public function whereFullText(Builder $query, $where)
    {
        return $this->compileFullTextMatch($query, $where['columns'], $where['options'], $this->parameter($where['value']));
    }

    /**
     * @param  string|list<string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function compileFullTextMatch(Builder $query, string|array $columns, array $options, string $value = '?'): string
    {
        [$document, $language, $function] = $this->fullTextParts($query, $columns, $options);
        $match = "{$document} @@ {$function}('{$language}', {$value})";

        if (! ($options['guard'] ?? false)) {
            return $match;
        }

        return "case when to_tsvector('{$language}', ?) = ''::tsvector then false else {$match} end";
    }

    /**
     * ts_rank() of the document; 0 for a search without any lexeme. Binds
     * the words of the search, then the search.
     *
     * @param  string|list<string>  $columns
     * @param  array<string, mixed>  $options
     */
    public function compileFullTextRank(Builder $query, string|array $columns, array $options): string
    {
        [$document, $language, $function] = $this->fullTextParts($query, $columns, $options);

        return "case when to_tsvector('{$language}', ?) = ''::tsvector then 0 else ts_rank({$document}, {$function}('{$language}', ?)) end";
    }

    /**
     * @param  string|list<string>  $columns
     * @param  array<string, mixed>  $options
     * @return array{0: string, 1: string, 2: string}
     */
    protected function fullTextParts(Builder $query, string|array $columns, array $options): array
    {
        $columns = array_values((array) $columns);
        $language = FullText::language($this->connection, $options['language'] ?? null);

        if ($options['vector'] ?? false) {
            if (count($columns) !== 1) {
                throw new FeatureNotSupportedException('CockroachDB cannot combine tsvector columns: search one tsvector column.');
            }

            $document = $this->wrap($columns[0]);
        } else {
            $document = FullText::document(array_map(fn ($column) => $this->wrap($column), $columns), $language);
        }

        return [$document, $language, FullText::queryFunction($options['mode'] ?? null)];
    }

    /**
     * Compile a truncate table statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array
     */
    public function compileTruncate(Builder $query)
    {
        return ['truncate ' . $this->wrapTable($query->from) . ' cascade' => []];
    }

    protected function compileJsonUpdateColumn($key, $value)
    {
        $segments = explode('->', $key);

        $field = $this->wrap(array_shift($segments));

        $path = "'{".implode(',', $this->wrapJsonPathAttributes($segments, '"'))."}'";

        return "{$field} = jsonb_set({$field}::jsonb, {$path}, ({$this->parameter($value)})::STRING::JSONB)";
    }
}
