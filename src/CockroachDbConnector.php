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
        if (isset($config['autocommit_before_ddl'])) {
            if($config['autocommit_before_ddl'] === true || $config['autocommit_before_ddl'] === 'on'){
                $connection->prepare("set autocommit_before_ddl = on;")->execute();
            }elseif($config['autocommit_before_ddl'] === false || $config['autocommit_before_ddl'] === 'off'){
                $connection->prepare("set autocommit_before_ddl = off;")->execute();
            }
        }
    }
    
}
