<?php

namespace YlsIdeas\CockroachDb\Tests\Integration\Scout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\EngineManager;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Searchable;
use YlsIdeas\CockroachDb\CockroachDbServiceProvider;
use YlsIdeas\CockroachDb\Scout\CockroachEngine;
use YlsIdeas\CockroachDb\Scout\SearchUsingFuzzy;
use YlsIdeas\CockroachDb\Tests\Integration\Database\DatabaseTestCase;

class ScoutArticle extends Model
{
    use Searchable;

    protected $table = 'scout_articles';

    public $timestamps = false;

    protected $guarded = [];

    /** Embeddings by topic, so the tests need no AI provider. */
    public const TOPICS = [
        'databases' => [1.0, 0.0, 0.0],
        'music' => [0.0, 1.0, 0.0],
        'mixed' => [0.7, 0.7, 0.0],
    ];

    #[SearchUsingFullText(['title', 'body'])]
    #[SearchUsingPrefix(['sku'])]
    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'body' => $this->body, 'sku' => $this->sku];
    }

    public function toSearchableEmbedding(): array
    {
        return self::TOPICS[$this->topic];
    }
}

class ScoutPlainArticle extends ScoutArticle
{
    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'sku' => $this->sku];
    }
}

class ScoutFuzzyArticle extends ScoutArticle
{
    #[SearchUsingFuzzy('title', unaccent: true)]
    public function toSearchableArray(): array
    {
        return ['title' => $this->title];
    }
}

/**
 * The CockroachDB Scout engine with deterministic query embeddings.
 */
class FakeEmbeddingsEngine extends CockroachEngine
{
    protected function generateEmbeddings(array $inputs): array
    {
        return array_map(fn ($input) => ScoutArticle::TOPICS[$input] ?? [0.0, 0.0, 1.0], array_values($inputs));
    }
}

class ScoutTest extends DatabaseTestCase
{
    protected function getPackageProviders($app)
    {
        return [CockroachDbServiceProvider::class, ScoutServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('scout.driver', 'crdb');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout.after_commit', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('scout_articles');
        Schema::create('scout_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('sku');
            $table->string('topic');
            $table->string('status')->default('published');
            $table->vector('embedding', 3)->nullable()->index();
            $table->fullText(['title', 'body']);
        });
        DB::statement('create index scout_articles_title_trigram on scout_articles using gin (unaccent(lower(title)) gin_trgm_ops)');

        $this->app->make(EngineManager::class)->extend('crdb', fn () => new FakeEmbeddingsEngine());

        ScoutArticle::create(['title' => 'CockroachDB basics', 'body' => 'a database for database people', 'sku' => 'DB-100', 'topic' => 'databases']);
        ScoutArticle::create(['title' => 'Guitar chords', 'body' => 'songs and music theory', 'sku' => 'MU-200', 'topic' => 'music']);
        ScoutArticle::create(['title' => 'Playlists in SQL', 'body' => 'store songs in a database', 'sku' => 'DB-300', 'topic' => 'mixed', 'status' => 'draft']);
        ScoutArticle::create(['title' => 'Bài hát tiếng Việt', 'body' => null, 'sku' => '50%_OFF', 'topic' => 'music']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scout_articles');

        parent::tearDown();
    }

    private function skus($builder): array
    {
        return $builder->get()->pluck('sku')->all();
    }

    public function test_the_engine_is_registered_and_stores_embeddings()
    {
        $this->assertInstanceOf(CockroachEngine::class, app(EngineManager::class)->engine('crdb'));
        $this->assertSame('[1,0,0]', str_replace(' ', '', (string) DB::table('scout_articles')->where('sku', 'DB-100')->value('embedding')));
    }

    public function test_full_text_is_ordered_by_relevance()
    {
        $this->assertSame(['DB-100', 'DB-300'], $this->skus(ScoutArticle::search('database')));
        $this->assertSame(['MU-200', 'DB-300'], array_values(array_intersect($this->skus(ScoutArticle::search('songs')), ['MU-200', 'DB-300'])));
        $this->assertSame([], $this->skus(ScoutArticle::search('the')));
    }

    public function test_like_columns_ignore_case_and_escape_wildcards()
    {
        $this->assertSame(['DB-300', 'DB-100'], $this->skus(ScoutPlainArticle::search('db-')));
        $this->assertSame(['DB-100'], $this->skus(ScoutPlainArticle::search('cockroach')));
        $this->assertSame(['50%_OFF'], $this->skus(ScoutPlainArticle::search('50%_')));
        $this->assertSame([], $this->skus(ScoutPlainArticle::search('50__')));
    }

    public function test_constraints_and_pagination()
    {
        $this->assertSame(['DB-100'], $this->skus(ScoutArticle::search('database')->where('status', 'published')));
        $page = ScoutArticle::search('database')->paginate(1);
        $this->assertSame(2, $page->total());
        $this->assertSame(['DB-100'], $page->pluck('sku')->all());
        $this->assertSame(['DB-100'], $this->skus(ScoutPlainArticle::search('1')));
    }

    public function test_fuzzy_columns()
    {
        $this->assertSame(['MU-200'], $this->skus(ScoutFuzzyArticle::search('gitar chord')));
        $this->assertSame(['50%_OFF'], $this->skus(ScoutFuzzyArticle::search('bai hat')));
        $this->assertSame('DB-100', $this->skus(ScoutFuzzyArticle::search('cockroach'))[0]);
    }

    public function test_semantic_search()
    {
        $this->assertSame(['DB-100', 'DB-300'], $this->skus(ScoutArticle::search('databases')->semantic(0.5)));
        $this->assertSame(['DB-100'], $this->skus(ScoutArticle::search('databases')->semantic(0.9)));
        $this->assertSame(['MU-200', '50%_OFF', 'DB-300'], $this->skus(ScoutArticle::search('music')->semantic(0.5)));
    }

    public function test_semantic_search_uses_the_vector_index()
    {
        DB::enableQueryLog();
        ScoutArticle::search('databases')->where('status', 'published')->semantic(0.5)->get();
        $query = collect(DB::getQueryLog())->last(fn ($query) => str_contains($query['query'], '<=>'));

        // The table is too small for the planner to pick the index: force it on the
        // candidates subquery, which CockroachDB refuses if the index cannot serve it.
        $index = collect(Schema::getIndexes('scout_articles'))->first(fn ($index) => $index['columns'] === ['embedding'])['name'];
        $sql = str_replace('from "scout_articles" order by', 'from "scout_articles"@{FORCE_INDEX='.$index.'} order by', $query['query']);
        $plan = collect(DB::select('explain '.$sql, $query['bindings']))->pluck('info')->implode("\n");

        $this->assertStringContainsString('vector search', $plan);
    }

    public function test_hybrid_search()
    {
        $results = $this->skus(ScoutArticle::search('songs')->hybrid());

        $this->assertSame('MU-200', $results[0] ?? null);
        $this->assertContains('DB-300', $results);
        $this->assertNotContains('DB-100', $results);
    }

    public function test_follower_reads()
    {
        $this->app->make(EngineManager::class)->forgetDrivers()->extend('crdb', fn () => new FakeEmbeddingsEngine(['follower_read' => true]));
        DB::enableQueryLog();

        // The results depend on the data of 4.8 seconds ago: only the SQL is checked.
        ScoutArticle::search('database')->raw();
        $this->assertStringContainsString('follower_read_timestamp()', collect(DB::getQueryLog())->pluck('query')->implode("\n"));
    }
}
