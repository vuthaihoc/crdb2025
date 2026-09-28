<?php

namespace YlsIdeas\CockroachDb\Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Sleep;
use Mockery as m;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use YlsIdeas\CockroachDb\CockroachDbConnection;

class SerializationRetryStatementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        m::close();

        parent::tearDown();
    }

    public function test_a_statement_outside_a_transaction_is_retried()
    {
        $statement = m::mock(PDOStatement::class);
        $statement->shouldReceive('setFetchMode', 'execute')->andReturnTrue();
        $statement->shouldReceive('fetchAll')->andReturn([(object) ['one' => 1]]);

        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('prepare')->once()->andThrow($this->serializationFailure());
        $pdo->shouldReceive('prepare')->once()->andReturn($statement);

        $this->assertEquals([(object) ['one' => 1]], $this->connection($pdo)->select('select 1 as one'));
        Sleep::assertSleptTimes(1);
    }

    public function test_a_statement_gives_up_after_the_retry_attempts()
    {
        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('prepare')->times(2)->andThrow($this->serializationFailure());

        try {
            $this->connection($pdo, ['retry_attempts' => 2])->select('select 1');
            $this->fail('The serialization failure was not thrown.');
        } catch (QueryException $e) {
            $this->assertSame('40001', $e->getCode());
        }

        Sleep::assertSleptTimes(1);
    }

    public function test_other_errors_are_not_retried()
    {
        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('prepare')->once()->andThrow(new PDOException('relation "missing" does not exist'));

        $this->expectException(QueryException::class);

        try {
            $this->connection($pdo)->select('select * from missing');
        } finally {
            Sleep::assertNeverSlept();
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function connection(PDO $pdo, array $config = []): CockroachDbConnection
    {
        return new CockroachDbConnection($pdo, 'forge', '', ['name' => 'crdb', 'driver' => 'crdb'] + $config);
    }

    private function serializationFailure(): PDOException
    {
        return new class ('SQLSTATE[40001]: Serialization failure: restart transaction: TransactionRetryWithProtoRefreshError') extends PDOException {
            protected $code = '40001';
        };
    }
}
