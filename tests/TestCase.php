<?php

declare(strict_types=1);

namespace Konekt\Search\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Konekt\Search\Providers\SearchServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PDO;

class TestCase extends OrchestraTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        Model::unguard();

        $this->app['config']->set('app.key', 'base64:yWa/ByhLC/GUvfToOuaPD7zDwB64qkc/QkaQOrT5IpE=');

        $this->initDatabase();
    }
    protected function getPackageProviders($app)
    {
        return [SearchServiceProvider::class];
    }

    protected function initDatabase($prefix = '')
    {
        DB::purge('mysql');

        $this->app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'search_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', 'secret'),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => $prefix,
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ]);

        $this->app['config']->set('database.connections.pgsql.prefix', $prefix);

        $this->artisan('db:wipe');
        $this->loadMigrationsFrom(__DIR__ . '/migrations');
        $this->artisan('migrate', ['--force' => true]);
    }
}
