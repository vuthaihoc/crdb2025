<?php

namespace YlsIdeas\CockroachDb\Scout;

use Attribute;
use Illuminate\Support\Arr;

/**
 * Columns of toSearchableArray() searched by trigram similarity (typo
 * tolerant) with the crdb Scout engine, served by a trigram index:
 * #[SearchUsingFuzzy(['word'], unaccent: true)].
 */
#[Attribute(Attribute::TARGET_METHOD)]
class SearchUsingFuzzy
{
    /** @var list<string> */
    public array $columns;

    /**
     * @param  string|list<string>  $columns
     * @param  float|null  $threshold  a minimum similarity, stricter than pg_trgm.similarity_threshold
     * @param  bool  $unaccent  compare unaccent(lower(...)), for an index on that expression
     */
    public function __construct(
        string|array $columns,
        public ?float $threshold = null,
        public bool $unaccent = false,
    ) {
        $this->columns = array_values(Arr::wrap($columns));
    }
}
