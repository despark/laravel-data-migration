<?php


namespace Despark\Migrations\Providers;


use Despark\Migrations\Console\Commands\Migrate;
use Despark\Migrations\Contracts\MigrationManagerContract;
use Despark\Migrations\MigrationManager;
use Illuminate\Support\ServiceProvider;

/**
 * Class MigrationServiceProvider.
 */
class MigrationServiceProvider extends ServiceProvider
{

    /**
     * @var bool
     */
    protected $defer = true;

    /**
     * Boot.
     */
    public function boot()
    {
        $this->publishes([__DIR__.'/../../config/migrations.php' => config_path('migrations.php')], 'config');
        $this->commands([Migrate::class]);
    }

    /**
     *
     */
    public function register()
    {
        $this->app->singleton(MigrationManagerContract::class, function ($app) {
            $migrationManager = new MigrationManager();
            if ($connection = config('migrations.database_connection')) {
                $migrationManager->setDatabaseConnection($connection);
            }
            foreach (config('migrations.migrations', []) as $name => $class) {
                $migrationManager->addMigration($name, $class);
            }

            return $migrationManager;
        });
    }

    /**
     * @return array
     */
    public function provides()
    {
        return [MigrationManagerContract::class];
    }

}