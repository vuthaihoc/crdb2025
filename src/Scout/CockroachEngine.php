<?php

namespace YlsIdeas\CockroachDb\Scout;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Exceptions\ScoutException;
use ReflectionMethod;
use YlsIdeas\CockroachDb\CockroachDbConnection;
use YlsIdeas\CockroachDb\Query\CockroachQueryBuilder;

/**
 * Scout's database engine for CockroachDB (SCOUT_DRIVER=crdb). Scout only
 * recognizes PostgreSQL by the "pgsql" driver name, so on CockroachDB its
 * database engine matches case-sensitively, never orders by relevance and
 * has no semantic search. This engine:
 *
 * - matches ilike, with the wildcards of the search escaped;
 * - orders by full-text relevance with the expression of the FULLTEXT index
 *   (Scout's own `to_tsvector(a) || to_tsvector(b)` fails on CockroachDB);
 * - searches #[SearchUsingFuzzy] columns by trigram similarity;
 * - runs semantic and hybrid search on VECTOR columns, through the vector
 *   index: CockroachDB only uses it for `order by distance limit k`, so the
 *   `vector_candidates` nearest rows (1000 by default) are selected first,
 *   then Scout's constraints and minimum similarity apply to them;
 * - with `'follower_read' => true` in config('scout.crdb'), reads
 *   through follower reads (data about 4.8 seconds old, no contention with
 *   writes).
 */
class CockroachEngine extends DatabaseEngine
{
    /**
     * Model casts of columns that are not text.
     */
    protected const NON_TEXT_CASTS = ['int', 'integer', 'float', 'double', 'real', 'decimal', 'bool', 'boolean'];

    /**
     * @param  array{follower_read?: bool, vector_candidates?: int}  $config  config('scout.crdb')
     */
    public function __construct(protected array $config = [])
    {
        parent::__construct();
    }

    /**
     * @param  Model  $model
     */
    protected function supportsVectorSearch($model): bool
    {
        return $model->getConnection() instanceof CockroachDbConnection;
    }

    /**
     * @param  Builder<Model>  $builder
     * @return EloquentBuilder<Model>
     */
    protected function newSearchQuery(Builder $builder)
    {
        $query = parent::newSearchQuery($builder);

        if (($this->config['follower_read'] ?? false) && $query->getQuery() instanceof CockroachQueryBuilder) {
            $query->getQuery()->followerRead();
        }

        return $query;
    }

    /**
     * Scout's semantic query, restricted to the nearest candidates so that
     * CockroachDB uses the vector index.
     *
     * @param  Builder<Model>  $builder
     * @return EloquentBuilder<Model>
     */
    protected function buildSemanticSearchQuery(Builder $builder)
    {
        $this->ensureSemanticSearchIsSupported($builder);

        if (! empty($builder->orders)) {
            throw new ScoutException('Database order clauses cannot be combined with semantic search.');
        }

        $vector = $this->generateEmbeddings([$builder->query])[0];

        return $this->constrainSearchQuery($builder, $this->semanticQuery($builder, $vector)->take($builder->limit));
    }

    /**
     * Scout's hybrid search (reciprocal rank fusion of the text and semantic
     * results), with the semantic query of buildSemanticSearchQuery().
     *
     * @param  Builder<Model>  $builder
     */
    protected function hybridSearchModels(Builder $builder, int $limit)
    {
        if (! empty($builder->orders)) {
            throw new ScoutException('Database order clauses cannot be combined with hybrid search.');
        }

        if ($limit <= 0) {
            return $builder->model->newCollection();
        }

        $vector = $this->generateEmbeddings([$builder->query])[0];

        $textQuery = $this->constrainSearchQuery($builder, $this->addTextSearchConstraints(
            $this->newSearchQuery($builder),
            $builder,
            array_keys($builder->model->toSearchableArray()), // @phpstan-ignore method.notFound
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder)
        )->take(1000));

        if ($this->shouldOrderBySimilarity($builder)) {
            $this->orderByRelevance($builder, $textQuery);
        } else {
            $textQuery->orderBy($builder->model->getTable().'.'.$builder->model->getScoutKeyName(), 'desc'); // @phpstan-ignore method.notFound
        }

        $textQuery->orderBy($builder->model->getQualifiedKeyName());

        $semanticQuery = $this->constrainSearchQuery($builder, $this->semanticQuery($builder, $vector)->take(1000));

        return $this->fuseSearchResults($builder, $textQuery->get(), $semanticQuery->get())->take($limit)->values();
    }

    /**
     * The rows within the minimum similarity among the nearest candidates,
     * nearest first.
     *
     * @param  Builder<Model>  $builder
     * @param  array<int, float>  $vector
     * @return EloquentBuilder<Model>
     */
    protected function semanticQuery(Builder $builder, array $vector)
    {
        $model = $builder->model;
        $column = $model->qualifyColumn($this->embeddingColumn($model));

        $candidates = $model->newQueryWithoutScopes()->toBase()
            ->select($model->getQualifiedKeyName())
            ->orderByVectorDistance($column, $vector)
            ->limit(max((int) ($this->config['vector_candidates'] ?? 1000), (int) $builder->limit));

        return $this->newSearchQuery($builder)
            ->whereIn($model->getQualifiedKeyName(), $candidates)
            ->whereVectorSimilarTo($column, $vector, $this->minimumSimilarity($builder), false)
            ->orderByVectorDistance($column, $vector)
            ->orderBy($model->getQualifiedKeyName());
    }

    /**
     * Scout's text constraints with ilike (escaped), trigram similarity for
     * #[SearchUsingFuzzy] columns and the driver's whereFullText().
     *
     * @param  EloquentBuilder<Model>  $query
     * @param  Builder<Model>  $builder
     * @param  array<int, string>  $columns
     * @param  array<int, string>  $prefixColumns
     * @param  array<int, string>  $fullTextColumns
     * @return EloquentBuilder<Model>
     */
    protected function addTextSearchConstraints($query, Builder $builder, array $columns, array $prefixColumns = [], array $fullTextColumns = [])
    {
        $model = $builder->model;

        if (method_exists($model, 'toSearchableEmbedding')) {
            $columns = array_values(array_diff($columns, [$this->embeddingColumn($model)]));
        }

        if (blank($builder->query)) {
            return $query;
        }

        $search = (string) $builder->query;
        $fuzzy = $this->fuzzy($builder);

        /** @var string $scoutKey */
        $scoutKey = $model->getScoutKeyName(); // @phpstan-ignore method.notFound

        return $query->where(function (EloquentBuilder $query) use ($search, $model, $builder, $scoutKey, $columns, $prefixColumns, $fullTextColumns, $fuzzy) {
            $canSearchPrimaryKey = ctype_digit($search)
                && in_array($model->getKeyType(), ['int', 'integer'], true)
                && $search <= PHP_INT_MAX
                && in_array($scoutKey, $columns, true);

            if ($canSearchPrimaryKey) {
                $query->orWhere($model->getQualifiedKeyName(), $search);
            }

            /** @var CockroachQueryBuilder $base */
            $base = $query->getQuery();

            $integerKey = in_array($model->getKeyType(), ['int', 'integer'], true);

            foreach ($columns as $column) {
                // CockroachDB has no ILIKE on numbers: an integer key is matched by equality above.
                if (in_array($column, $fullTextColumns, true) || ($integerKey && $column === $scoutKey)) {
                    continue;
                }

                $qualified = $model->qualifyColumn($column);

                match (true) {
                    // Cast to text: a cast would keep a text column from its trigram index.
                    $model->hasCast($column, self::NON_TEXT_CASTS) => $base->whereRaw(
                        $base->getGrammar()->wrap($qualified).'::string ilike ?',
                        ['%'.CockroachQueryBuilder::escapeLike($search).'%'],
                        'or'
                    ),
                    $fuzzy !== null && in_array($column, $fuzzy->columns, true) => $base->where(fn (CockroachQueryBuilder $q) => $q
                        ->whereContains($qualified, $search, $fuzzy->unaccent)
                        ->whereSimilar($qualified, $search, $fuzzy->threshold, $fuzzy->unaccent, 'or'), boolean: 'or'),
                    in_array($column, $prefixColumns, true) => $base->whereStartsWith($qualified, $search, false, 'or'),
                    default => $base->whereContains($qualified, $search, false, 'or'),
                };
            }

            if ($fullTextColumns !== []) {
                $query->orWhereFullText(
                    array_map(fn ($column) => $model->qualifyColumn($column), $fullTextColumns),
                    $search,
                    $this->getFullTextOptions($builder)
                );
            }
        });
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function shouldOrderByRelevance(Builder $builder): bool
    {
        return $this->shouldOrderBySimilarity($builder) && $this->getFullTextColumns($builder) !== [];
    }

    /**
     * Relevance applies to searches without developer-defined orders, on
     * models with full-text or fuzzy columns.
     *
     * @param  Builder<Model>  $builder
     */
    protected function shouldOrderBySimilarity(Builder $builder): bool
    {
        return $builder->model->getConnection() instanceof CockroachDbConnection
            && ($this->getFullTextColumns($builder) !== [] || $this->fuzzy($builder) !== null)
            && empty($builder->orders)
            && ! blank($builder->query);
    }

    /**
     * Full-text relevance (ts_rank), then the similarity of the fuzzy columns.
     *
     * @param  Builder<Model>  $builder
     * @param  EloquentBuilder<Model>  $query
     * @return EloquentBuilder<Model>
     */
    protected function orderByRelevance(Builder $builder, $query)
    {
        $model = $builder->model;
        $search = (string) $builder->query;
        /** @var CockroachQueryBuilder $base */
        $base = $query->getQuery();

        if (($fullTextColumns = $this->getFullTextColumns($builder)) !== []) {
            $base->orderByFullTextRelevance(
                array_map(fn ($column) => $model->qualifyColumn($column), $fullTextColumns),
                $search,
                $this->getFullTextOptions($builder)
            );
        }

        if (($fuzzy = $this->fuzzy($builder)) !== null) {
            foreach ($fuzzy->columns as $column) {
                $base->orderBySimilarity($model->qualifyColumn($column), $search, 'desc', $fuzzy->unaccent);
            }
        }

        return $query;
    }

    /**
     * Scout orders models without full-text columns latest first: for fuzzy
     * columns, similarity comes before that.
     *
     * @param  Builder<Model>  $builder
     * @return EloquentBuilder<Model>
     */
    protected function buildSearchQuery(Builder $builder)
    {
        $query = parent::buildSearchQuery($builder);

        if ($this->getFullTextColumns($builder) === [] && $this->shouldOrderBySimilarity($builder)) {
            $this->orderByRelevance($builder, $query);
        }

        return $query;
    }

    /**
     * The #[SearchUsingFuzzy] attribute of the model's toSearchableArray().
     *
     * @param  Builder<Model>  $builder
     */
    protected function fuzzy(Builder $builder): ?SearchUsingFuzzy
    {
        $attributes = (new ReflectionMethod($builder->model, 'toSearchableArray'))->getAttributes(SearchUsingFuzzy::class);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
