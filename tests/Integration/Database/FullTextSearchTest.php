<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FullTextSearchTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ft_articles');
        Schema::create('ft_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->fullText(['title', 'body']);
        });

        DB::table('ft_articles')->insert([
            ['title' => 'Running databases', 'body' => 'Fast queries on large tables'],
            ['title' => 'Cooking at home', 'body' => null],
            ['title' => 'Slow queries', 'body' => 'Why a query runs slowly'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ft_articles');

        parent::tearDown();
    }

    private function titles(string $search, array $options = []): array
    {
        return DB::table('ft_articles')->whereFullText(['title', 'body'], $search, $options)->orderBy('id')->pluck('title')->all();
    }

    /**
     * The plan of a query forced onto an index: CockroachDB rejects the
     * hint when the query does not match the index expression.
     */
    private function planWithIndex(string $sql, string $index): string
    {
        $sql = str_replace('from "ft_articles"', 'from "ft_articles"@{FORCE_INDEX='.$index.'}', $sql);

        return collect(DB::select('explain '.$sql))->pluck('info')->implode("\n");
    }

    public function test_the_query_matches_the_fulltext_index()
    {
        $sql = DB::table('ft_articles')->whereFullText(['title', 'body'], 'cooking')->toRawSql();

        $this->assertStringContainsString('table: ft_articles@ft_articles_title_body_fulltext', $this->planWithIndex($sql, 'ft_articles_title_body_fulltext'));
    }

    public function test_rows_with_a_null_column_are_found()
    {
        $this->assertSame(['Cooking at home'], $this->titles('cooking'));
    }

    public function test_searches_without_lexemes_match_nothing()
    {
        $this->assertSame([], $this->titles('the'));
        $this->assertSame([], $this->titles('!!'));
        $this->assertSame([], $this->titles(''));
        $this->assertSame(['Running databases'], $this->titles('database!?'));
    }

    public function test_modes()
    {
        $this->assertSame(['Running databases'], $this->titles('fast queries', ['mode' => 'phrase']));
        $this->assertSame(['Running databases'], $this->titles('"fast queries" -slow', ['mode' => 'websearch']));
        $this->assertSame(['Running databases', 'Cooking at home'], $this->titles('database or cook', ['mode' => 'websearch']));
        $this->assertSame(['Running databases', 'Slow queries'], $this->titles('quer:*', ['mode' => 'raw']));
    }

    public function test_relevance()
    {
        $rows = DB::table('ft_articles')
            ->select('title')
            ->selectFullTextRelevance(['title', 'body'], 'query')
            ->searchFullText(['title', 'body'], 'query')
            ->get();

        $this->assertSame(['Slow queries', 'Running databases'], $rows->pluck('title')->all());
        $this->assertGreaterThan($rows[1]->relevance, $rows[0]->relevance);
        $this->assertSame([], DB::table('ft_articles')->searchFullText('title', 'the')->pluck('title')->all());
    }

    public function test_the_connection_language_is_used_by_indexes_and_queries()
    {
        config()->set('database.connections.crdb.fulltext_language', 'simple');
        DB::purge('crdb');

        Schema::table('ft_articles', fn (Blueprint $table) => $table->fullText('title', 'ft_articles_title_simple'));

        $sql = DB::table('ft_articles')->whereFullText('title', 'running')->toRawSql();

        $this->assertStringContainsString("to_tsvector('simple'", $sql);
        $this->assertStringContainsString('table: ft_articles@ft_articles_title_simple', $this->planWithIndex($sql, 'ft_articles_title_simple'));
        // No stemming with 'simple': "run" does not find "Running".
        $this->assertSame([], DB::table('ft_articles')->whereFullText('title', 'run')->pluck('title')->all());
    }

    public function test_recreating_an_index_with_another_language()
    {
        Schema::table('ft_articles', function (Blueprint $table) {
            $table->dropFullText(['title', 'body']);
            $table->fullText(['title', 'body'])->language('simple');
        });

        $sql = DB::table('ft_articles')->whereFullText(['title', 'body'], 'running', ['language' => 'simple'])->toRawSql();

        $this->assertStringContainsString('ft_articles_title_body_fulltext', $this->planWithIndex($sql, 'ft_articles_title_body_fulltext'));
        $this->assertSame(['Running databases'], $this->titles('running', ['language' => 'simple']));
    }

    public function test_a_tsvector_column()
    {
        Schema::table('ft_articles', function (Blueprint $table) {
            $table->tsvector('search')->storedAs("to_tsvector('english', title)");
            $table->index('search', null, 'gin');
        });

        $this->assertSame(
            ['Slow queries'],
            DB::table('ft_articles')->whereFullText('search', 'slow', ['vector' => true])->pluck('title')->all()
        );
    }
}
