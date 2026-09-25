<?php

namespace YlsIdeas\CockroachDb;

use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Connectors\PostgresConnector;

class CockroachDbConnector extends PostgresConnector implements ConnectorInterface
{
    /**
     * When using CockroachDB serverless it's possible to apply a namespace to the name of the database
     * which then allows for the service to recognise which cluster is being used.
     */
    protected function getDsn(array $config): string
    {
        if (($config['cluster'] ?? false) && $config['cluster'] != '') {
            $config['database'] = implode('.', [$config['cluster'], $config['database']]);
        }

        return parent::getDsn($config);
    }

    protected function configureTimezone($connection, array $config)
    {
        if (isset($config['timezone'])) {
            $timezone = $config['timezone'];

            $connection->prepare("set time zone '{$timezone}'")->execute();
        }
        $this->configureSessionVariables($connection, $config);

        if (isset($config['autocommit_before_ddl'])) {
            if ($config['autocommit_before_ddl'] === true || $config['autocommit_before_ddl'] === 'on') {
                $connection->prepare("set autocommit_before_ddl = on;")->execute();
            } elseif ($config['autocommit_before_ddl'] === false || $config['autocommit_before_ddl'] === 'off') {
                $connection->prepare("set autocommit_before_ddl = off;")->execute();
            }
        }
    }

    /**
     * Apply the `variables` option with SET, e.g.
     * 'variables' => ['default_int_size' => 4, 'autocommit_before_ddl' => 'off'].
     */
    protected function configureSessionVariables($connection, array $config): void
    {
        foreach ($config['variables'] ?? [] as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name)) {
                throw new \InvalidArgumentException("Invalid session variable name [{$name}].");
            }

            $value = match (true) {
                is_bool($value) => $value ? 'on' : 'off',
                is_int($value), is_float($value) => (string) $value,
                default => $connection->quote((string) $value),
            };

            $connection->exec("set {$name} = {$value}");
        }
    }
}
