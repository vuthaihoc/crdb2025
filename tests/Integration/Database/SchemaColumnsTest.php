<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SchemaColumnsTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('late_primary_keys');

        parent::tearDown();
    }

    public function test_dropped_columns_are_not_listed()
    {
        // primary() is added after the table is created, which drops CockroachDB's hidden rowid column.
        Schema::create('late_primary_keys', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('name');
        });

        Schema::table('late_primary_keys', fn (Blueprint $table) => $table->string('extra')->nullable());
        Schema::table('late_primary_keys', fn (Blueprint $table) => $table->dropColumn('extra'));

        $this->assertSame(['id', 'name'], Schema::getColumnListing('late_primary_keys'));
        $this->assertSame(['id', 'name'], array_column(Schema::getColumns('late_primary_keys'), 'name'));
    }
}
