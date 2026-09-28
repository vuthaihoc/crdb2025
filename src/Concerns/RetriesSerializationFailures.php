<?php

namespace YlsIdeas\CockroachDb\Concerns;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * CockroachDB runs every transaction as SERIALIZABLE and reports contention as SQLSTATE 40001
 * ("restart transaction"), which the client is expected to retry.
 *
 * - DB::transaction() without an explicit attempt count uses the `retry_attempts` option.
 * - A statement that fails with 40001 outside a transaction is retried: its implicit
 *   transaction was rolled back as a whole.
 * - Retries wait an exponential backoff with jitter (`retry_base_delay` / `retry_max_delay`, ms).
 */
trait RetriesSerializationFailures
{
    /**
     * @param  int  $attempts
     * @return mixed
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        if (func_num_args() < 2) {
            $attempts = $this->retryAttempts();
        }

        return parent::transaction($callback, $attempts);
    }

    /**
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     */
    protected function handleTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        // Throws unless the transaction is to be retried.
        parent::handleTransactionException($e, $currentAttempt, $maxAttempts);

        $this->backOffBeforeRetry($currentAttempt);
    }

    /**
     * @param  int  $currentAttempt
     * @param  int  $maxAttempts
     * @return void
     */
    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        // Throws unless the transaction is to be retried.
        parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);

        $this->backOffBeforeRetry($currentAttempt);
    }

    /**
     * @param  string  $query
     * @param  array<mixed>  $bindings
     * @return mixed
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        // Inside a transaction the whole transaction must be retried, by transaction().
        if ($this->transactions >= 1 || ! $this->causedByConcurrencyError($e)) {
            return parent::handleQueryException($e, $query, $bindings, $callback);
        }

        for ($attempt = 1; $attempt < $this->retryAttempts(); $attempt++) {
            $this->backOffBeforeRetry($attempt);

            try {
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $retry) {
                if (! $this->causedByConcurrencyError($retry)) {
                    return parent::handleQueryException($retry, $query, $bindings, $callback);
                }

                $e = $retry;
            }
        }

        throw $e;
    }

    /**
     * @return bool
     */
    protected function causedByConcurrencyError(Throwable $e)
    {
        return parent::causedByConcurrencyError($e)
            || str_contains($e->getMessage(), 'restart transaction');
    }

    protected function retryAttempts(): int
    {
        return max(1, (int) ($this->getConfig('retry_attempts') ?? 3));
    }

    /**
     * Wait before the retry following $attempt: half of the capped exponential delay plus a random half.
     */
    protected function backOffBeforeRetry(int $attempt): void
    {
        $base = (int) ($this->getConfig('retry_base_delay') ?? 50);
        $max = (int) ($this->getConfig('retry_max_delay') ?? 1000);
        $delay = min($max, $base * 2 ** min($attempt - 1, 20)) * 1000;

        if ($delay > 0) {
            Sleep::usleep(intdiv($delay, 2) + random_int(0, intdiv($delay, 2)));
        }
    }
}
