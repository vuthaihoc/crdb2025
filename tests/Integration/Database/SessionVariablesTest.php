<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SessionVariablesTest extends DatabaseTestCase
{
    private function reconnectWith(array $variables): void
    {
        config()->set('database.connections.crdb.variables', $variables);
        DB::purge('crdb');
    }

    public function test_variables_are_applied_on_connect()
    {
        $this->reconnectWith(['default_int_size' => 4, 'application_name' => 'portable tests']);

        $this->assertSame('4', DB::scalar('show default_int_size'));
        $this->assertSame('portable tests', DB::scalar('show application_name'));
    }

    public function test_invalid_variable_names_are_rejected()
    {
        $this->reconnectWith(['default_int_size; drop table x' => 4]);

        try {
            DB::connection('crdb')->getPdo();
            $this->fail('An invalid variable name must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->reconnectWith([]);
        }
    }

    public function test_migrations_run_outside_transactions_while_ddl_autocommits()
    {
        $this->assertSame('on', DB::scalar('show autocommit_before_ddl'));
        DB::connection()->useDefaultSchemaGrammar();
        $this->assertFalse(DB::connection()->getSchemaGrammar()->supportsSchemaTransactions());

        $this->reconnectWith(['autocommit_before_ddl' => 'off']);

        $this->assertSame('off', DB::scalar('show autocommit_before_ddl'));
        DB::connection()->useDefaultSchemaGrammar();
        $this->assertTrue(DB::connection()->getSchemaGrammar()->supportsSchemaTransactions());
    }
}
