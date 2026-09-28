<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class HistoricalReadsTest extends DatabaseTestCase
{
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed()
    {
        Schema::create('hr_orders', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->integer('total');
        });
    }

    protected function destroyDatabaseMigrations()
    {
        Schema::dropIfExists('hr_orders');
    }

    public function test_sql()
    {
        $sql = DB::table('hr_orders as o')
            ->join('hr_orders as p', 'p.id', '=', 'o.id')
            ->asOfSystemTime('-10s')
            ->where('o.status', 'paid')
            ->toSql();

        $this->assertSame(
            'select * from "hr_orders" as "o" inner join "hr_orders" as "p" on "p"."id" = "o"."id" as of system time \'-10s\' where "o"."status" = ?',
            $sql
        );
        $this->assertStringEndsWith(
            'from "hr_orders" as of system time follower_read_timestamp()',
            DB::table('hr_orders')->followerRead()->toSql()
        );
        $this->assertStringContainsString(
            "as of system time '2026-01-02 03:04:05.000000+00:00'",
            DB::table('hr_orders')->asOfSystemTime(new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC')))->toSql()
        );
        $this->assertStringNotContainsString(
            'as of system time',
            DB::table('hr_orders')->followerRead()->withoutHistoricalRead()->toSql()
        );
    }

    public function test_invalid_values_are_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        DB::table('hr_orders')->asOfSystemTime("-10s'; drop table hr_orders; --");
    }

    public function test_reads_the_past()
    {
        DB::table('hr_orders')->insert(['status' => 'paid', 'total' => 10]);
        sleep(2);
        DB::table('hr_orders')->insert(['status' => 'paid', 'total' => 20]);

        $this->assertSame(2, DB::table('hr_orders')->count());
        $this->assertSame(1, DB::table('hr_orders')->asOfSystemTime('-1s')->count());
        $this->assertEquals(10, DB::table('hr_orders')->asOfSystemTime('-1s')->sum('total'));
    }

    public function test_follower_read_and_eloquent()
    {
        DB::table('hr_orders')->insert(['status' => 'paid', 'total' => 10]);
        sleep(6);

        $this->assertSame(1, DB::table('hr_orders')->followerRead()->count());
        $this->assertSame(1, HrOrder::query()->followerRead()->where('status', 'paid')->count());
        $this->assertSame(1, HrOrder::query()->asOfSystemTime('-5s')->paginate(10)->total());
    }

    public function test_inside_a_transaction_the_current_data_is_read()
    {
        DB::table('hr_orders')->insert(['status' => 'paid', 'total' => 10]);

        DB::transaction(function () {
            DB::table('hr_orders')->insert(['status' => 'paid', 'total' => 20]);

            $this->assertSame(2, DB::table('hr_orders')->followerRead()->count());
        });
    }
}

class HrOrder extends Model
{
    protected $table = 'hr_orders';

    protected $guarded = [];

    public $timestamps = false;
}
