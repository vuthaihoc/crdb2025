<?php

namespace YlsIdeas\CockroachDb\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Collection;
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

    public function whereFullText(Builder $query, $where)
    {
        $language = $where['options']['language'] ?? 'english';

        if (! in_array($language, $this->validFullTextLanguages())) {
            $language = 'english';
        }

        //        $columns = (new Collection($where['columns']))->map(function ($column) use ($language) {
        //            return "to_tsvector('{$language}', {$this->wrap($column)})";
        //        })->implode(' || ');

        $columns = array_map(function ($column) {
            return "({$this->wrap($column)})";
        }, $where['columns']);
        $columns = implode(' || \' \' || ', $columns);

        $mode = 'plainto_tsquery';

        if (($where['options']['mode'] ?? []) === 'phrase') {
            $mode = 'phraseto_tsquery';
        }

        //        if (($where['options']['mode'] ?? []) === 'websearch') {
        //            $mode = 'websearch_to_tsquery';
        //        }

        if (($where['options']['mode'] ?? []) === 'custom') {
            $mode = 'to_tsquery';
        }

        return "to_tsvector('{$language}', {$columns}) @@ {$mode}('{$language}', {$this->parameter($where['value'])})";
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
