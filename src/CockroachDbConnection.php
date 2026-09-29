<?php

namespace YlsIdeas\CockroachDb;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Grammar as BaseGrammar;
use Illuminate\Database\PostgresConnection;
use Illuminate\Filesystem\Filesystem;
use YlsIdeas\CockroachDb\Builder\CockroachDbBuilder as DbBuilder;
use YlsIdeas\CockroachDb\Concerns\RetriesSerializationFailures;
use YlsIdeas\CockroachDb\Processor\CockroachDbProcessor as DbProcessor;
use YlsIdeas\CockroachDb\Query\CockroachGrammar as QueryGrammar;
use YlsIdeas\CockroachDb\Query\CockroachQueryBuilder;
use YlsIdeas\CockroachDb\Schema\CockroachDbGrammar as SchemaGrammar;
use YlsIdeas\CockroachDb\Schema\CockroachSchemaState as SchemaState;

class CockroachDbConnection extends PostgresConnection implements ConnectionInterface
{
    use RetriesSerializationFailures;

    /**
     * Get the default query grammar instance.
     *
     * @return BaseGrammar
     */
    protected function getDefaultQueryGrammar(): BaseGrammar
    {
        // Laravel 12 grammars read the table prefix from their connection.
        return new QueryGrammar($this);
    }

    /**
     * Get a new query builder instance, with historical reads.
     *
     * @return CockroachQueryBuilder
     */
    public function query()
    {
        return new CockroachQueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return DbBuilder
     */
    public function getSchemaBuilder(): DbBuilder
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new DbBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return BaseGrammar
     */
    protected function getDefaultSchemaGrammar(): BaseGrammar
    {
        return new SchemaGrammar($this);
    }

    /**
     * Get the schema state for the connection. CockroachSchemaState extends
     * SchemaState, not PostgresSchemaState (different dump and load commands).
     *
     * @return SchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null) // @phpstan-ignore method.childReturnType
    {
        return new SchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default post processor instance.
     *
     * @return DbProcessor
     */
    protected function getDefaultPostProcessor(): DbProcessor
    {
        return new DbProcessor();
    }
}
