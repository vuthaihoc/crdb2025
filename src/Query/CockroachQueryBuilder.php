<?php

namespace YlsIdeas\CockroachDb\Query;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Query builder with CockroachDB's historical reads (AS OF SYSTEM TIME).
 */
class CockroachQueryBuilder extends Builder
{
    /**
     * The AS OF SYSTEM TIME expression of the query, compiled after its joins.
     */
    public ?string $asOfSystemTime = null;

    /**
     * Read the data as it was at a point in the past:
     * asOfSystemTime('-10s'), asOfSystemTime(now()->subMinute()).
     *
     * The read takes no locks and never conflicts with writes. CockroachDB only
     * accepts it on a top-level SELECT (not in subqueries), and it is left out
     * inside a transaction, where CockroachDB rejects it.
     *
     * @return $this
     */
    public function asOfSystemTime(DateTimeInterface|string $time): static
    {
        if ($time instanceof DateTimeInterface) {
            $time = $time->format('Y-m-d H:i:s.uP');
        } elseif (! preg_match('/^-?\d+(\.\d+)?(us|µs|ms|s|m|h)(\d+(\.\d+)?(us|µs|ms|s|m|h))*$|^\d{4}-\d{2}-\d{2}/', $time)) {
            throw new InvalidArgumentException("Invalid AS OF SYSTEM TIME value [{$time}]: use an interval such as '-10s' or a timestamp.");
        }

        $this->asOfSystemTime = "'".str_replace("'", "''", $time)."'";

        return $this;
    }

    /**
     * A follower read: data about 4.8 seconds old, which any replica can
     * serve (the nearest one in a multi-node cluster), with no contention
     * with writes. Suited to dashboards and reports.
     *
     * @return $this
     */
    public function followerRead(): static
    {
        $this->asOfSystemTime = 'follower_read_timestamp()';

        return $this;
    }

    /**
     * Read at the current time again.
     *
     * @return $this
     */
    public function withoutHistoricalRead(): static
    {
        $this->asOfSystemTime = null;

        return $this;
    }
}
