# CockroachDB Driver for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vuthaihoc/cockroachdb-laravel.svg?style=flat-square)](https://packagist.org/packages/vuthaihoc/cockroachdb-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/vuthaihoc/cockroachdb-laravel.svg?style=flat-square)](https://packagist.org/packages/vuthaihoc/cockroachdb-laravel)
[![Help Fund](https://img.shields.io/github/sponsors/peterfox?style=flat-square)](https://github.com/sponsors/peterfox)
[![License](http://poser.pugx.org/vuthaihoc/cockroachdb-laravel/license)](https://packagist.org/packages/vuthaihoc/cockroachdb-laravel)
[![PHP Version Require](http://poser.pugx.org/vuthaihoc/cockroachdb-laravel/require/php)](https://packagist.org/packages/vuthaihoc/cockroachdb-laravel)

This is a fork of [ylsideas/cockroachdb-laravel](https://github.com/ylsideas/cockroachdb-laravel) by Peter Fox, maintained for Laravel 12 and 13.

A driver/grammar for Laravel that works with CockroachDB. While CockroachDB is compatible with Postgresql, this support
is not 1 to 1 meaning you may run into issues, this driver hopes to resolve those problems as much as possible.

Support Laravel 12

_Support Laravel 11 see [V1 Branch](https://github.com/vuthaihoc/crdb2025/tree/v1)_

### Supporting Open Source

[Peter Fox](https://www.peterfox.me) here, I just want to say this project has been my hardest yet. It's been a real labour of love to make and takes
up a lot of time trying to organise the test suite so that compatibility is maintained between Eloquent and CockroachDB.

I see a lot of promise in using CockroachDB's serverless offering which is what compelled me to go down this route originally.
You can read [an article](https://medium.com/@SlyFireFox/laravel-tip-cockroachdbs-serverless-database-322aa7f5f7ef) 
I made about using their service.

If you're using this project at all then do please consider [sponsoring me](https://github.com/sponsors/peterfox) 
as a way of encouraging more development.

## Installation

You can install the package via composer:

```bash
composer require vuthaihoc/cockroachdb-laravel
```

You need to add the connection type to the database config:
```php
'crdb' => [
    'driver' => 'crdb',
    'url' => env('DATABASE_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '26257'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'schema' => 'public',
    'sslmode' => 'prefer',
]
```

You can also use URLs.

```dotenv
DATABASE_URL=cockroachdb://<username>:<password>@<host>:<port>/<database>?sslmode=verify-full
```

## Usage

To enable set `DB_CONNECTION=crdb` in your .env.

## Notes

CockroachDB should work inline with the feature set of Postgresql, with some exceptions. You can look at the
features of each CockroachDB server in the CockroachDB [Docs](https://www.cockroachlabs.com/docs/stable/sql-feature-support.html).

### Deletes with Joins
CockroachDB does not support performing deletes using joins. If you wish to
do something like this you will need to use a sub-query instead.

At current if you try to call the `delete` method of the Query builder together with a `join` then
a `YlsIdeas\CockroachDb\Exceptions\FeatureNotSupportedException` exception will be thrown.

### Full-text search
Laravel's own API works: `$table->fullText([...])` creates a GIN index, and `whereFullText()` searches
exactly the expression of that index, so the index is used:

```php
$table->fullText(['title', 'description']);                    // migration

Post::whereFullText(['title', 'description'], $search)->get();
Post::searchFullText(['title', 'description'], $search)->get(); // matches, most relevant first
Post::select('*')->selectFullTextRelevance(['title', 'description'], $search)->get();   // ts_rank as "relevance"
```

- **Several columns**: CockroachDB has no `tsvector || tsvector` (PostgreSQL's way), so the columns are joined as
  text: `to_tsvector('english', coalesce(title, '') || ' ' || coalesce(description, ''))`. A NULL column does
  not hide the row.
- **Language**: `['language' => 'simple']` per query and `->language('simple')` per index, else the connection's
  `fulltext_language`, else `english`. The index and the query must use the same one. CockroachDB has simple,
  danish, dutch, english, finnish, french, german, hungarian, italian, norwegian, portuguese, russian, spanish,
  swedish and turkish; another language throws. `simple` (no stemming, no stopwords) suits Vietnamese and CJK.
- **Modes**: default `plainto_tsquery`, `'phrase'`, `'raw'` (`to_tsquery` syntax such as `run:* & !slow`) and
  `'websearch'` (`"a phrase" -excluded or other`), translated because CockroachDB has no `websearch_to_tsquery`.
- A search made only of stopwords or punctuation ("the", "!!") is an error on CockroachDB; the driver makes it
  match nothing, as PostgreSQL does.
- **A tsvector column** (large tables): `tsvector` generated column with a GIN index, searched with
  `['vector' => true]`:

```php
$table->tsvector('search')->storedAs("to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, ''))");
$table->index('search', null, 'gin');

Post::whereFullText('search', $search, ['vector' => true, 'language' => 'simple'])->get();
```

**Upgrading from 2.2**: the index expression changed (coalesce, and `english` instead of `simple` when no
language is given), so existing full-text indexes no longer match `whereFullText()`. Recreate them:

```php
Schema::table('posts', function (Blueprint $table) {
    $table->dropFullText(['title', 'description']);
    $table->fullText(['title', 'description'])->language('simple');
});
```

### Suggestions and fuzzy search (trigrams)
```php
Word::whereStartsWith('word', $search)->get();         // ilike 'search%'
Word::whereContains('word', $search)->get();           // ilike '%search%'
Word::whereSimilar('word', $search)->get();            // word % 'search': typo tolerant
Word::whereSimilar('word', $search, 0.5)->get();       // and similarity() >= 0.5
Word::select('*')->selectSimilarity('word', $search)->orderBySimilarity('word', $search)->get();
Word::suggest('word', $search)->limit(10)->get();      // search box
```

- `%`, `_` and `\` in the search are matched literally.
- `suggest()`: under 3 characters, the values starting with the search; from 3 characters, also the values
  containing it or similar to it. Values starting with the search come first, then the most similar, then the
  shortest.
- `unaccent: true` ignores accents and case, "chao" finds "chào":
  `Word::suggest('word', $search, unaccent: true)`.
- `%` uses the session's `pg_trgm.similarity_threshold` (0.3); set it with `'variables' => ['pg_trgm.similarity_threshold' => 0.2]`.
- CockroachDB has `similarity()` but not `word_similarity()`, `<%` or `<->`.
- Index: `create index words_word_trigram on words using gin (word gin_trgm_ops)`, or
  `using gin (unaccent(lower(word)) gin_trgm_ops)` for `unaccent: true`. The `trigramIndex()` macro of
  [laravel-db-portable](https://github.com/vuthaihoc/laravel-db-portable) creates both.

### Laravel Scout
The package registers a Scout engine for CockroachDB (Scout 11.8+). Use it instead of Scout's `database` engine,
which only recognizes PostgreSQL by the `pgsql` driver name (on CockroachDB it matches case-sensitively, never
orders by relevance and has no semantic search):

```dotenv
SCOUT_DRIVER=crdb
```

```php
// config/scout.php
'crdb' => [
    'follower_read' => false,     // true: search data about 4.8 seconds old, served by any replica, no contention with writes
    'vector_candidates' => 1000,  // semantic search: nearest rows taken from the vector index before filtering
],
```

```php
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;
use YlsIdeas\CockroachDb\Scout\SearchUsingFuzzy;

class Word extends Model
{
    use Searchable;

    #[SearchUsingFullText(['definition'], ['language' => 'simple'])]
    #[SearchUsingPrefix(['code'])]
    #[SearchUsingFuzzy(['word'], unaccent: true)]    // typo tolerant, "chao" finds "chào"
    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'word' => $this->word, 'code' => $this->code, 'definition' => $this->definition];
    }

    // Optional: semantic and hybrid search on a vector column named "embedding" (or searchableEmbeddingColumn()).
    // A string is embedded with the Laravel AI SDK (laravel/ai); an array is stored as is.
    public function toSearchableEmbedding(): string|array
    {
        return $this->word."\n".$this->definition;
    }
}

Word::search('chao')->get();                        // like, prefix, fuzzy and full-text columns, most relevant first
Word::search('greeting')->where('language_code', 'vi')->paginate(20);
Word::search('how to say hello')->semantic()->get();  // cosine similarity
Word::search('hello')->hybrid()->get();               // rank fusion of text and semantic results
```

- Other columns match `ilike '%search%'` (`#[SearchUsingPrefix]`: `ilike 'search%'`), with `%` and `_` in the search
  matched literally; columns cast to numbers or booleans in the model are compared as text. An integer key is
  matched by equality when the search is a number.
- Full-text columns use the driver's `whereFullText()` (see above) and are ordered by `ts_rank`.
- `#[SearchUsingFuzzy]` columns match values containing the search or similar to it (`%`, trigram index), ordered by
  similarity; `threshold:` sets a stricter minimum similarity.
- Semantic search needs a `vector` column (`$table->vector('embedding', 1536)->index()` creates a vector index)
  and uses cosine distance (`<=>`); `->semantic(0.8)` sets the minimum similarity. CockroachDB only uses the
  vector index for `order by distance limit k`, not with other conditions, so the engine takes the
  `vector_candidates` nearest rows from the index, then applies Scout's `where` constraints and the minimum
  similarity to them: like any approximate search, a strongly filtered search may return fewer rows than exist.
- Indexes: `$table->fullText([...])` for full-text columns, a trigram index for like and fuzzy columns (`trigramIndex()`
  of laravel-db-portable, with `unaccent: true` for `SearchUsingFuzzy(..., unaccent: true)`).

Coming from `vuthaihoc/scout-crdb-driver`: `SearchUsingFuzzy` becomes this package's attribute (CockroachDB has no
`word_similarity()`, so it compares whole values), `SearchUsingTrigram` columns are plain columns (`ilike '%...%'`),
`SearchUsingExact` is Scout's `SearchUsingPrefix` or a `where()`, and `crdb:indexes` is replaced by migrations.

### Migrations and `autocommit_before_ddl`
Since v25, CockroachDB commits the open transaction before every DDL statement (`autocommit_before_ddl = on`),
so a migration wrapped in a transaction fails with "There is no active transaction". The driver therefore
runs migrations outside transactions, like MySQL. To keep transactional migrations, turn the setting off:

```php
'crdb' => [
    // ...
    'variables' => ['autocommit_before_ddl' => 'off'],
],
```

### Changing columns and `schema_locked`
Since v26, CockroachDB creates tables with `schema_locked = true` and rejects identity changes on a locked
table. `->change()` therefore leaves out Laravel's `drop identity if exists` when the column has no identity,
drops an identity in its own statement, and unlocks the table around identity changes (locking it again).

### Session variables
The `variables` option runs `SET <name> = <value>` on every new connection:

```php
'variables' => [
    'default_int_size' => 4,
    'application_name' => 'my-app',
],
```

### Strict integers (portable to MySQL and MatrixOne)
CockroachDB's `integer` is 64-bit and it does not check MySQL's `tinyint` or unsigned ranges, so data written
through `integer()`, `tinyInteger()` or `unsigned*()` columns may not fit when moving to MySQL, MariaDB or
MatrixOne. `strict_integers` keeps every integer column within its MySQL range:

```php
'crdb' => [
    // ...
    'strict_integers' => true,
],
```

| Blueprint | Column | Check |
|-----------|--------|-------|
| `integer()` | `INT4` | native range |
| `unsignedInteger()` | `INT8` | `between 0 and 4294967295` |
| `mediumInteger()` | `INT4` | `between -8388608 and 8388607` (unsigned: `0` and `16777215`) |
| `smallInteger()` | `INT2` | native range (unsigned: `INT4`, `0` to `65535`) |
| `tinyInteger()` | `INT2` | `between -128 and 127` (unsigned: `0` and `255`) |
| `unsignedBigInteger()`, `foreignId()` | `INT8` | `>= 0` |

Auto-increment columns (`id()`, `increments()`) are unchanged. The option applies when columns are created;
`->change()` only changes the type. It is off by default so existing schemas keep their behaviour.

### Historical reads and follower reads
`AS OF SYSTEM TIME` reads data as it was in the past. The read takes no locks and never conflicts with writes. A follower read (about 4.8 seconds old) can be served by any replica, the nearest one in a multi-node cluster. Both suit dashboards and reports, which can show data a few seconds old:

```php
Order::query()->followerRead()->where('status', 'paid')->sum('total');
DB::table('orders')->asOfSystemTime('-10s')->count();
DB::table('orders')->asOfSystemTime(now()->subHour())->get();
```

- The clause goes on the top-level `SELECT`; CockroachDB rejects it in subqueries.
- Inside a transaction CockroachDB rejects it too, so the query reads current data there. This also keeps tests that run in `DatabaseTransactions` working.
- `withoutHistoricalRead()` removes it again.

### Serverless Support
Cockroach Serverless requires you to provide a cluster with connection.
Laravel doesn't provide this out of the box, so, it's being implemented as an extra `cluster` parameter in the 
database config. Just pass the cluster identification from CockroachDB Serverless.

### Schema Dumps
You may use schema dumps. I'm not 100% sure the functionality is correct in line with other drivers.
Please raise an issue if it isn't working as expect for you.

```php
'crdb' => [
    'driver' => 'crdb',
    'url' => env('DATABASE_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '26257'),
    'database' => env('DB_DATABASE', 'forge'),
    'username' => env('DB_USERNAME', 'forge'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'schema' => 'public',
    'sslmode' => 'prefer',
    'cluster' => env('COCKROACHDB_CLUSTER', ''),
]
```

You may also use a URL in the following format.

```dotenv
DATABASE_URL=cockroachdb://<username>:<password>@<host>:<port>/<database>?sslmode=verify-full&cluster=<cluster>
```

## Related packages

- [vuthaihoc/laravel-db-portable](https://github.com/vuthaihoc/laravel-db-portable): query builder and schema macros that compile for CockroachDB/PostgreSQL, MySQL/MatrixOne and SQLite, and `db-portable:scan` / `audit` / `copy` commands for moving between databases.
- [vuthaihoc/laravel-matrixone](https://github.com/vuthaihoc/laravel-matrixone): the MatrixOne driver. `strict_integers` keeps CockroachDB integer columns within MySQL ranges, so data copied to MatrixOne fits.

## Testing

The tests try to closely follow the same functionality of the grammar provided by Laravel
by lifting the tests straight from laravel/framework. This does provide some complications.
Namely, cockroachdb is designed to be distributed so primary keys do not occur in sequence.

The test suite currently targets Laravel 12. The `tests/Database/Laravel11` tests are skipped on Laravel 12.

You can run up a local cockroachDB test instance using Docker compose.
```shell
docker-composer up -d
```

If you need to you may run the docker compose file with different cockroachdb
versions
```shell
VERSION=v23.1.13 docker-compose up -d
```

Then run the following PHP script to create a test database and user
```shell
php ./database.php
```

Afterwards you can run the test suite. `DB_HOST` / `DB_PORT` point it at another server, for example a
throwaway in-memory node:
```bash
docker run -d --name crdb-test -p 127.0.0.1:26258:26257 cockroachdb/cockroach:v26.2.6 start-single-node --insecure --store=type=mem,size=1GiB
DB_PORT=26258 php ./database.php
DB_PORT=26258 composer test
```

To clean up, you only need stop docker composer.
```shell
docker-composer down
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Peter Fox](https://github.com/peterfox)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
