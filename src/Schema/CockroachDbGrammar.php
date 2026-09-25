<?php

namespace YlsIdeas\CockroachDb\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use YlsIdeas\CockroachDb\Exceptions\FeatureNotSupportedException;

class CockroachDbGrammar extends PostgresGrammar
{
    /**
     * The possible column modifiers; IntegerRange implements `strict_integers`.
     *
     * @var string[]
     */
    protected $modifiers = ['Collate', 'Nullable', 'Default', 'VirtualAs', 'StoredAs', 'GeneratedAs', 'Increment', 'IntegerRange'];

    /**
     * MySQL ranges of Laravel's integer column types, enforced with CHECK
     * constraints when `strict_integers` is on.
     *
     * @var array<string, array{signed: array{int, int}, unsigned: array{int, int}}>
     */
    protected array $integerRanges = [
        'tinyInteger' => ['signed' => [-128, 127], 'unsigned' => [0, 255]],
        'smallInteger' => ['signed' => [-32768, 32767], 'unsigned' => [0, 65535]],
        'mediumInteger' => ['signed' => [-8388608, 8388607], 'unsigned' => [0, 16777215]],
        'integer' => ['signed' => [-2147483648, 2147483647], 'unsigned' => [0, 4294967295]],
    ];

    /**
     * Compile the query to determine the tables.
     *
     * CockroachDB doesn't yet support pg_total_relation_size()
     * https://github.com/cockroachdb/cockroach/issues/20712
     * https://github.com/cockroachdb/cockroach/pull/59604
     *
     * @return string
     */
    public function compileTables($schema)
    {
        return "select table_name as name, table_schema as schema, -1 as size, null as comment
            from information_schema.tables
            where table_type = 'BASE TABLE' and "
            . $this->compileSchemaWhereClause($schema, 'table_schema') . "
            order by table_schema, table_name";
    }

    /**
     * CockroachDB 25+ commits the open transaction before every DDL statement
     * (autocommit_before_ddl = on), so wrapping a migration in a transaction
     * fails with "There is no active transaction". Migrations only run inside
     * a transaction when the connection turns the setting off.
     *
     * @return bool
     */
    public function supportsSchemaTransactions()
    {
        $config = $this->connection->getConfig();
        $setting = $config['variables']['autocommit_before_ddl'] ?? $config['autocommit_before_ddl'] ?? null;

        return in_array($setting, [false, 'off', 0, '0'], true);
    }

    /**
     * With `strict_integers`, integer() is INT4 (CockroachDB's `integer` is INT8)
     * and unsigned columns get room for their MySQL range.
     */
    protected function typeInteger(Fluent $column)
    {
        if (! $this->usesStrictIntegers() || $this->isSerial($column)) {
            return parent::typeInteger($column);
        }

        return $column->unsigned ? 'int8' : 'int4';
    }

    protected function typeMediumInteger(Fluent $column)
    {
        if (! $this->usesStrictIntegers() || $this->isSerial($column)) {
            return parent::typeMediumInteger($column);
        }

        return 'int4';
    }

    protected function typeSmallInteger(Fluent $column)
    {
        if (! $this->usesStrictIntegers() || $this->isSerial($column) || ! $column->unsigned) {
            return parent::typeSmallInteger($column);
        }

        return 'int4';
    }

    protected function typeTinyInteger(Fluent $column)
    {
        if (! $this->usesStrictIntegers() || $this->isSerial($column)) {
            return parent::typeTinyInteger($column);
        }

        return 'int2';
    }

    /**
     * With `strict_integers`, keep integer columns within their MySQL range so
     * the data can move to MySQL-compatible databases (CockroachDB only checks
     * the width of INT2/INT4 columns, not tinyint or unsigned ranges).
     *
     * @return string|null
     */
    protected function modifyIntegerRange(Blueprint $blueprint, Fluent $column)
    {
        if (! $this->usesStrictIntegers() || $column->change || $this->isSerial($column)) {
            return null;
        }

        if ($column->type === 'bigInteger') {
            return $column->unsigned ? sprintf(' check (%s >= 0)', $this->wrap($column->name)) : null;
        }

        $range = $this->integerRanges[$column->type][$column->unsigned ? 'unsigned' : 'signed'] ?? null;

        return $range ? sprintf(' check (%s between %d and %d)', $this->wrap($column->name), $range[0], $range[1]) : null;
    }

    protected function usesStrictIntegers(): bool
    {
        return (bool) $this->connection->getConfig('strict_integers');
    }

    protected function isSerial(Fluent $column): bool
    {
        return $column->autoIncrement && is_null($column->generatedAs) && ! $column->change;
    }

    /**
     * CockroachDB keeps dropped columns in pg_attribute (attisdropped), e.g.
     * the hidden rowid column dropped when a primary key is added after the
     * table is created, as Laravel does for `->primary()`.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileColumns($schema, $table)
    {
        return str_replace(
            'where c.relname = ',
            'where not a.attisdropped and c.relname = ',
            parent::compileColumns($schema, $table)
        );
    }

    public function compileIndex(Blueprint $blueprint, Fluent $command)
    {
        if (strtoupper($command->algorithm) == 'GIN') {
            return parent::compileIndex($blueprint, $command);
        }

        return sprintf(
            'create index %s on %s (%s)%s',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $this->columnize($command->columns),
            $command->algorithm ? ' using '.$command->algorithm : '',
        );
    }

    /**
     * Compile a fulltext index key command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     *
     * @throws \RuntimeException
     */
    public function compileFulltext(Blueprint $blueprint, Fluent $command)
    {
        //        throw new FeatureNotSupportedException('Fulltext indexes are not supported by CockroachDB as of version 2.5');
        $language = $command->language ?: 'simple';

        $columns = array_map(function ($column) use ($language) {
            return "({$this->wrap($column)})";
        }, $command->columns);
        $columns = implode(' || \' \' || ', $columns);

        return sprintf(
            'create index %s on %s using gin (to_tsvector(%s, %s))',
            $this->wrap($command->index),
            $this->wrapTable($blueprint),
            $this->quoteString($language),
            $columns,
        );
    }

    /**
     * Compile a drop fulltext index command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropFullText(Blueprint $blueprint, Fluent $command)
    {
        return $this->compileDropIndex($blueprint, $command);
    }

    /**
     * Compile a drop unique key command.
     *
     * CockroachDB doesn't support alter table for dropping unique indexes.
     * https://github.com/cockroachdb/cockroach/issues/42840?version=v22.1
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return string
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        $index = $this->wrap($command->get('index'));

        return "drop index {$this->wrapTable($blueprint)}@{$index} cascade";
    }
}
