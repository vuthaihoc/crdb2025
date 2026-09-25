<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StrictIntegersTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('strict_numbers');

        parent::tearDown();
    }

    private function createTable(): void
    {
        Schema::create('strict_numbers', function (Blueprint $table) {
            $table->id();
            $table->integer('views')->default(0);
            $table->unsignedInteger('big_views')->nullable();
            $table->tinyInteger('mood')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedSmallInteger('points')->nullable();
            $table->mediumInteger('medium')->nullable();
            $table->foreignId('owner_id')->nullable();
        });
    }

    private function types(): array
    {
        return collect(DB::select(
            "select column_name, crdb_sql_type from information_schema.columns where table_name = 'strict_numbers'"
        ))->pluck('crdb_sql_type', 'column_name')->all();
    }

    private function rejects(array $values): bool
    {
        try {
            DB::table('strict_numbers')->insert($values);

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    public function test_integers_are_int8_without_the_option()
    {
        $this->createTable();

        $this->assertSame('INT8', $this->types()['views']);
        $this->assertFalse($this->rejects(['views' => 3_000_000_000, 'score' => 500]));
    }

    public function test_strict_integers_use_mysql_ranges()
    {
        config()->set('database.connections.crdb.strict_integers', true);
        DB::purge('crdb');

        $this->createTable();

        $types = $this->types();
        $this->assertSame('INT8', $types['id']);
        $this->assertSame('INT4', $types['views']);
        $this->assertSame('INT8', $types['big_views']);
        $this->assertSame('INT2', $types['score']);
        $this->assertSame('INT4', $types['points']);
        $this->assertSame('INT4', $types['medium']);

        $this->assertFalse($this->rejects([
            'views' => 2_147_483_647, 'big_views' => 4_294_967_295, 'mood' => -128, 'score' => 255,
            'points' => 65_535, 'medium' => 8_388_607, 'owner_id' => 1,
        ]));

        $this->assertTrue($this->rejects(['views' => 3_000_000_000]));
        $this->assertTrue($this->rejects(['big_views' => -1]));
        $this->assertTrue($this->rejects(['big_views' => 4_294_967_296]));
        $this->assertTrue($this->rejects(['mood' => 128]));
        $this->assertTrue($this->rejects(['score' => 500]));
        $this->assertTrue($this->rejects(['points' => 65_536]));
        $this->assertTrue($this->rejects(['medium' => 8_388_608]));
        $this->assertTrue($this->rejects(['owner_id' => -1]));
    }

    public function test_changing_a_column_keeps_working()
    {
        config()->set('database.connections.crdb.strict_integers', true);
        DB::purge('crdb');

        $this->createTable();

        Schema::table('strict_numbers', fn (Blueprint $table) => $table->bigInteger('views')->default(0)->change());

        $this->assertSame('INT8', $this->types()['views']);
    }
}
