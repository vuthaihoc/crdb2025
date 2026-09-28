<?php

namespace YlsIdeas\CockroachDb\Tests\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression as Raw;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use YlsIdeas\CockroachDb\Exceptions\FeatureNotSupportedException;
use YlsIdeas\CockroachDb\Processor\CockroachDbProcessor;
use YlsIdeas\CockroachDb\Query\CockroachGrammar;
use YlsIdeas\CockroachDb\Query\CockroachQueryBuilder;

class DatabaseCockroachDbQueryBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_where_time_operator_optional()
    {
        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->whereTime('created_at', '22:00');
        $this->assertSame('select * from "users" where "created_at"::time = ?', $builder->toSql());
        $this->assertEquals([0 => '22:00'], $builder->getBindings());
    }

    public function test_where_date()
    {
        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->whereDate('created_at', '=', '2015-12-21');
        $this->assertSame('select * from "users" where "created_at"::date = ?', $builder->toSql());
        $this->assertEquals([0 => '2015-12-21'], $builder->getBindings());

        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->whereDate('created_at', new Raw('NOW()'));
        $this->assertSame('select * from "users" where "created_at"::date = NOW()', $builder->toSql());
    }

    public function test_where_day()
    {
        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->whereDay('created_at', '=', 1);
        $this->assertSame('select * from "users" where extract(day from "created_at") = ?', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function test_where_month()
    {
        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->whereMonth('created_at', '=', 5);
        $this->assertSame('select * from "users" where extract(month from "created_at") = ?', $builder->toSql());
        $this->assertEquals([0 => 5], $builder->getBindings());
    }

    public function test_where_year()
    {
        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->whereYear('created_at', '=', 2014);
        $this->assertSame('select * from "users" where extract(year from "created_at") = ?', $builder->toSql());
        $this->assertEquals([0 => 2014], $builder->getBindings());
    }

    public function test_where_time()
    {
        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->whereTime('created_at', '>=', '22:00');
        $this->assertSame('select * from "users" where "created_at"::time >= ?', $builder->toSql());
        $this->assertEquals([0 => '22:00'], $builder->getBindings());
    }

    public function test_where_like()
    {
        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->where('id', 'like', '1');
        $this->assertSame('select * from "users" where "id"::text like ?', $builder->toSql());
        $this->assertEquals([0 => '1'], $builder->getBindings());

        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->where('id', 'LIKE', '1');
        $this->assertSame('select * from "users" where "id"::text LIKE ?', $builder->toSql());
        $this->assertEquals([0 => '1'], $builder->getBindings());

        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->where('id', 'ilike', '1');
        $this->assertSame('select * from "users" where "id"::text ilike ?', $builder->toSql());
        $this->assertEquals([0 => '1'], $builder->getBindings());

        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->where('id', 'not like', '1');
        $this->assertSame('select * from "users" where "id"::text not like ?', $builder->toSql());
        $this->assertEquals([0 => '1'], $builder->getBindings());

        $builder = $this->getCockroachDbBuilder();
        $builder->select('*')->from('users')->where('id', 'not ilike', '1');
        $this->assertSame('select * from "users" where "id"::text not ilike ?', $builder->toSql());
        $this->assertEquals([0 => '1'], $builder->getBindings());
    }

    public function test_update_method_with_joins()
    {
        $builder = $this->getCockroachDbBuilder();
        $builder->getConnection()
            ->shouldReceive('update')
            ->once()
            ->with('update "users" set "admin" = ? from "blocklist" where "user"."email" = "blocklist"."email"', [0 => false])
            ->andReturn(1);
        $result = $builder
            ->from('users')
            ->join('blocklist', 'user.email', '=', 'blocklist.email')
            ->update(['admin' => false]);
        $this->assertEquals(1, $result);
    }

    public function test_deletes_with_joins_throw_an_exception()
    {
        $this->expectException(FeatureNotSupportedException::class);
        $builder = $this->getCockroachDbBuilder();
        $builder->from('users')->join('blocklist', 'email', '=', 'email')->delete();
        $builder->toSql();
    }

    public function test_where_full_text_compiles_to_text_search()
    {
        // A base builder (e.g. a join clause) binds the search only.
        $builder = $this->getCockroachDbBuilder();
        $builder->getConnection()->shouldReceive('getConfig')->andReturn(null);
        $builder->select('*')->from('users')->whereFullText(['name', 'description'], 'should contain');

        $this->assertSame(
            'select * from "users" where to_tsvector(\'english\', coalesce("name", \'\') || \' \' || coalesce("description", \'\')) @@ plainto_tsquery(\'english\', ?)',
            $builder->toSql()
        );
    }

    public function test_where_full_text_guards_searches_without_lexemes()
    {
        $builder = $this->getCockroachQueryBuilder(['fulltext_language' => 'simple']);
        $builder->select('*')->from('users')->where('id', 1)->whereFullText('description', 'c++ rocks!');

        $this->assertSame(
            'select * from "users" where "id" = ? and case when to_tsvector(\'simple\', ?) = \'\'::tsvector then false else to_tsvector(\'simple\', coalesce("description", \'\')) @@ plainto_tsquery(\'simple\', ?) end',
            $builder->toSql()
        );
        $this->assertSame([1, 'c rocks', 'c rocks'], $builder->getBindings());
    }

    public function test_websearch_is_translated_to_tsquery()
    {
        $builder = $this->getCockroachQueryBuilder();
        $builder->select('*')->from('posts')->whereFullText('body', '"fast query" -slow cook or bake', ['mode' => 'websearch', 'vector' => true]);

        $this->assertStringContainsString('"body" @@ to_tsquery(\'english\', ?)', $builder->toSql());
        $this->assertSame(['fast query slow cook bake', '(fast <-> query) & !slow & cook | bake'], $builder->getBindings());
    }

    public function test_unsupported_languages_throw()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->getCockroachQueryBuilder()->from('posts')->whereFullText('body', 'x', ['language' => 'arabic'])->toSql();
    }

    public function test_full_text_relevance()
    {
        $builder = $this->getCockroachQueryBuilder();
        $builder->select('id')->from('posts')->searchFullText('body', 'fast');

        $this->assertSame(
            'select "id" from "posts" where case when to_tsvector(\'english\', ?) = \'\'::tsvector then false else to_tsvector(\'english\', coalesce("body", \'\')) @@ plainto_tsquery(\'english\', ?) end order by case when to_tsvector(\'english\', ?) = \'\'::tsvector then 0 else ts_rank(to_tsvector(\'english\', coalesce("body", \'\')), plainto_tsquery(\'english\', ?)) end desc',
            $builder->toSql()
        );
        $this->assertSame(['fast', 'fast', 'fast', 'fast'], $builder->getBindings());
    }

    public function test_trigram_search()
    {
        $builder = $this->getCockroachQueryBuilder();
        $builder->select('*')->from('words')->whereContains('word', '50%_off')->whereSimilar('word', 'aple', 0.4, unaccent: true, boolean: 'or');

        $this->assertSame(
            'select * from "words" where "word" ilike ? or (unaccent(lower("word")) % unaccent(lower(?)) and similarity(unaccent(lower("word")), unaccent(lower(?))) >= ?)',
            $builder->toSql()
        );
        $this->assertSame(['%50\\%\\_off%', 'aple', 'aple', 0.4], $builder->getBindings());
    }

    public function test_suggest()
    {
        $builder = $this->getCockroachQueryBuilder();
        $builder->select('*')->from('words')->suggest('word', 'apl');

        $this->assertSame(
            'select * from "words" where ("word" ilike ? or "word" % ?) order by "word" ilike ? desc, similarity("word", ?) desc, length("word"), "word" asc',
            $builder->toSql()
        );
        $this->assertSame(['%apl%', 'apl', 'apl%', 'apl'], $builder->getBindings());

        $short = $this->getCockroachQueryBuilder()->from('words')->suggest('word', 'ap');
        $this->assertSame('select * from "words" where "word" ilike ? order by length("word"), "word" asc', $short->toSql());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function getCockroachQueryBuilder(array $config = []): CockroachQueryBuilder
    {
        $connection = $this->getConnection();
        $connection->shouldReceive('getConfig')->andReturnUsing(fn ($key) => $config[$key] ?? null);

        return new CockroachQueryBuilder($connection, new CockroachGrammar($connection), m::mock(Processor::class));
    }

    protected function getConnection()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('database');
        $connection->shouldReceive('getTablePrefix')->andReturn('');

        return $connection;
    }

    protected function getBuilder()
    {
        $connection = $this->getConnection();
        $grammar = new Grammar($connection);
        $processor = m::mock(Processor::class);

        return new Builder($connection, $grammar, $processor);
    }

    protected function getCockroachDbBuilder()
    {
        $connection = $this->getConnection();
        $grammar = new CockroachGrammar($connection);
        $processor = m::mock(Processor::class);

        return new Builder($connection, $grammar, $processor);
    }

    protected function getCockroachDbBuilderWithProcessor()
    {
        $connection = $this->getConnection();
        $grammar = new CockroachGrammar($connection);
        $processor = new CockroachDbProcessor();

        return new Builder($connection, $grammar, $processor);
    }
}
