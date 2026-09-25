# CockroachDB Driver for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/vuthaihoc/cockroachdb-laravel.svg?style=flat-square)](https://packagist.org/packages/vuthaihoc/cockroachdb-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/vuthaihoc/cockroachdb-laravel.svg?style=flat-square)](https://packagist.org/packages/vuthaihoc/cockroachdb-laravel)
[![Help Fund](https://img.shields.io/github/sponsors/peterfox?style=flat-square)](https://github.com/sponsors/peterfox)
[![License](http://poser.pugx.org/vuthaihoc/cockroachdb-laravel/license)](https://packagist.org/packages/vuthaihoc/cockroachdb-laravel)
[![PHP Version Require](http://poser.pugx.org/vuthaihoc/cockroachdb-laravel/require/php)](https://packagist.org/packages/vuthaihoc/cockroachdb-laravel)

A driver/grammar for Laravel that works with CockroachDB. This is a fork of [ylsideas/cockroachdb-laravel](https://github.com/ylsideas/cockroachdb-laravel) by Peter Fox, maintained for Laravel 12 and 13.

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

### Fulltext Search
CockroachDB supports full-text search since v23.1. `$table->fullText([...])` creates a GIN index on
`to_tsvector(...)` and `whereFullText()` compiles to `to_tsvector(...) @@ plainto_tsquery(...)`, as on PostgreSQL.

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
docker run -d --name crdb-test -p 127.0.0.1:26258:26257 cockroachdb/cockroach:v25.3.2 start-single-node --insecure --store=type=mem,size=1GiB
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
