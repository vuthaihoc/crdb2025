<?php

namespace YlsIdeas\CockroachDb;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use YlsIdeas\CockroachDb\Scout\CockroachEngine;

class CockroachDbServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->extend(DatabaseManager::class, function (DatabaseManager $manager) {
            ConfigurationUrlParser::addDriverAlias('cockroachdb', 'crdb');

            Connection::resolverFor('crdb', function ($connection, $database, $prefix, $config) {
                $connector = new CockroachDbConnector();
                $connection = $connector->connect($config);

                return new CockroachDbConnection($connection, $database, $prefix, $config);
            });

            return $manager;
        });

        // SCOUT_DRIVER=crdb, when Laravel Scout is installed.
        if (class_exists(EngineManager::class)) {
            $this->app->resolving(EngineManager::class, function (EngineManager $manager) {
                $manager->extend('crdb', fn ($app) => new CockroachEngine((array) $app['config']->get('scout.crdb', [])));
            });
        }
    }
}
