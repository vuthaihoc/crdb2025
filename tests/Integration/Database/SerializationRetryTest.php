<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

class SerializationRetryTest extends DatabaseTestCase
{
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed()
    {
        Schema::create('counters', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('value');
        });

        DB::table('counters')->insert(['id' => 1, 'value' => 0]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        // A second session, to write between the read and the write of the transaction under test.
        config()->set('database.connections.other', config('database.connections.'.config('database.default')));
    }

    public function test_a_transaction_is_retried_after_a_serialization_failure()
    {
        $attempts = $this->incrementWithConflict(fn ($callback) => DB::transaction($callback));

        $this->assertSame(2, $attempts);
        $this->assertSame(2, DB::table('counters')->where('id', 1)->value('value'));
        Sleep::assertSleptTimes(1);
    }

    public function test_an_explicit_attempt_count_is_kept()
    {
        $attempts = 0;

        try {
            $this->incrementWithConflict(fn ($callback) => DB::transaction($callback, 1), $attempts);
            $this->fail('The serialization failure was not thrown.');
        } catch (QueryException $e) {
            $this->assertSame('40001', (string) $e->getCode());
        }

        $this->assertSame(1, $attempts);
        $this->assertSame(1, DB::table('counters')->where('id', 1)->value('value'));
        Sleep::assertNeverSlept();
    }

    public function test_retry_attempts_option()
    {
        $connection = config('database.default');
        config()->set("database.connections.$connection.retry_attempts", 1);
        DB::purge($connection);

        $attempts = 0;

        try {
            $this->incrementWithConflict(fn ($callback) => DB::transaction($callback), $attempts);
            $this->fail('The serialization failure was not thrown.');
        } catch (QueryException $e) {
            $this->assertSame('40001', (string) $e->getCode());
        }

        $this->assertSame(1, $attempts);
    }

    /**
     * Read the counter, let the other session increment it, then write read + 1: the first
     * attempt conflicts with the other session's write.
     *
     * @param  callable(\Closure): mixed  $transaction
     */
    private function incrementWithConflict(callable $transaction, int &$attempts = 0): int
    {
        $transaction(function () use (&$attempts) {
            $attempts++;
            $value = DB::table('counters')->where('id', 1)->value('value');

            if ($attempts === 1) {
                DB::connection('other')->table('counters')->where('id', 1)->increment('value');
            }

            DB::table('counters')->where('id', 1)->update(['value' => $value + 1]);
        });

        return $attempts;
    }
}
