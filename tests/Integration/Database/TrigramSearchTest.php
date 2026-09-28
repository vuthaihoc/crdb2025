<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrigramSearchTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('trgm_words');
        Schema::create('trgm_words', function (Blueprint $table) {
            $table->id();
            $table->string('word');
        });
        DB::statement('create index trgm_words_word_trigram on trgm_words using gin (word gin_trgm_ops)');
        DB::statement('create index trgm_words_word_unaccent on trgm_words using gin (unaccent(lower(word)) gin_trgm_ops)');

        DB::table('trgm_words')->insert(array_map(fn ($word) => ['word' => $word], [
            'apple', 'Application', 'apply', 'banana', 'Xin chào', 'cháo lòng', '50% off', '50 of',
        ]));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('trgm_words');

        parent::tearDown();
    }

    private function words(callable $callback): array
    {
        return $callback(DB::table('trgm_words'))->pluck('word')->all();
    }

    public function test_starts_with_and_contains_ignore_case_and_escape_wildcards()
    {
        $this->assertSame(['Application', 'apple', 'apply'], $this->words(fn ($q) => $q->whereStartsWith('word', 'APP')->orderBy('word')));
        $this->assertSame(['50% off'], $this->words(fn ($q) => $q->whereContains('word', '0% o')));
        $this->assertSame([], $this->words(fn ($q) => $q->whereStartsWith('word', '_')));
    }

    public function test_unaccent()
    {
        $this->assertSame(['Xin chào', 'cháo lòng'], $this->words(fn ($q) => $q->whereContains('word', 'chao', unaccent: true)->orderBy('id')));
        $this->assertSame(['cháo lòng'], $this->words(fn ($q) => $q->whereStartsWith('word', 'CHAO', unaccent: true)));
    }

    public function test_similar_is_typo_tolerant_and_uses_the_index()
    {
        $this->assertSame(['apple'], $this->words(fn ($q) => $q->whereSimilar('word', 'aple', 0.4)));

        $rows = DB::table('trgm_words')->select('word')->selectSimilarity('word', 'aple')
            ->whereSimilar('word', 'aple')->orderBySimilarity('word', 'aple')->get();
        $this->assertSame('apple', $rows[0]->word);
        $this->assertGreaterThan(0.3, $rows[0]->similarity);

        foreach (['trgm_words_word_trigram' => false, 'trgm_words_word_unaccent' => true] as $index => $unaccent) {
            $sql = str_replace('from "trgm_words"', 'from "trgm_words"@{FORCE_INDEX='.$index.'}', DB::table('trgm_words')->whereSimilar('word', 'aple', unaccent: $unaccent)->toRawSql());
            $this->assertStringContainsString("table: trgm_words@{$index}", collect(DB::select('explain '.$sql))->pluck('info')->implode("\n"));
        }
    }

    public function test_suggest()
    {
        $this->assertSame(['apple', 'apply', 'Application'], $this->words(fn ($q) => $q->suggest('word', 'ap')));
        $this->assertSame('apple', $this->words(fn ($q) => $q->suggest('word', 'appel'))[0]);
        $this->assertSame('Application', $this->words(fn ($q) => $q->suggest('word', 'applic'))[0]);
        $this->assertSame([], $this->words(fn ($q) => $q->suggest('word', ' ')));
        $this->assertContains('Xin chào', $this->words(fn ($q) => $q->suggest('word', 'xin chao', unaccent: true)));
    }
}
