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
        $language = $where['options']['language'] ?? 'simple';

        if (! in_array($language, $this->validFullTextLanguages())) {
            $language = 'simple';
        }

//        $columns = (new Collection($where['columns']))->map(function ($column) use ($language) {
//            return "to_tsvector('{$language}', {$this->wrap($column)})";
//        })->implode(' || ');

        $columns = array_map(function ($column) use ($language) {
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

        return "({$columns}) @@ {$mode}('{$language}', {$this->parameter($where['value'])})";
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

}