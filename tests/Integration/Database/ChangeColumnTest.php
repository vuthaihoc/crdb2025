<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CockroachDB 26 creates tables with schema_locked = true, and rejects
 * `drop identity` on a locked table.
 */
class ChangeColumnTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('changed_columns');

        parent::tearDown();
    }

    public function test_changing_a_column_does_not_touch_identity()
    {
        Schema::create('changed_columns', function (Blueprint $table) {
            $table->id();
            $table->integer('views')->default(0);
        });

        Schema::table('changed_columns', fn (Blueprint $table) => $table->bigInteger('views')->default(1)->change());

        $column = collect(Schema::getColumns('changed_columns'))->firstWhere('name', 'views');
        $this->assertSame('int8', $column['type_name']);
        $this->assertStringContainsString('1', (string) $column['default']);
    }

    public function test_dropping_an_identity_unlocks_the_table_and_locks_it_again()
    {
        Schema::create('changed_columns', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->bigInteger('seq')->generatedAs();
        });
        $locked = $this->isLocked();

        Schema::table('changed_columns', fn (Blueprint $table) => $table->bigInteger('seq')->default(0)->change());

        $this->assertSame(
            [],
            DB::select("select 1 from information_schema.columns where table_name = 'changed_columns' and is_identity = 'YES'")
        );
        $this->assertSame($locked, $this->isLocked());
    }

    public function test_pretending_a_change_does_not_query_the_table()
    {
        $queries = DB::pretend(function () {
            Schema::table('missing_table', fn (Blueprint $table) => $table->bigInteger('views')->change());
        });

        $this->assertSame(
            'alter table "missing_table" alter column "views" type bigint, alter column "views" set not null, alter column "views" drop default',
            $queries[0]['query']
        );
        $this->assertStringNotContainsString('information_schema', implode(';', array_column($queries, 'query')));
    }

    private function isLocked(): bool
    {
        $row = DB::selectOne('show create table changed_columns');

        return str_contains($row->create_statement, 'schema_locked = true');
    }
}
