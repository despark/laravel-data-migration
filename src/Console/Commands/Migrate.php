<?php

namespace Despark\Migrations\Console\Commands;

use Despark\Migrations\Contracts\MigrationManagerContract;
use Despark\Migrations\Contracts\UsesProgressBar;
use Despark\Migrations\Migration;
use Illuminate\Console\Command;
use League\Flysystem\Exception;

/**
 * Class Migrate.
 */
class Migrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrations:migrate {--m|migration=* : Migration names} ' .
    '{values?* : any custom data to pass to the migration "key=value"} ' .
    '{--no-progress : No progress bar} ' .
    '{--t|test}';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate data form old providers';


    /**
     * @var MigrationManagerContract
     */
    protected $manager;

    /**
     * Create a new command instance.
     *
     * @param MigrationManagerContract $manager
     */
    public function __construct(MigrationManagerContract $manager)
    {
        parent::__construct();
        $this->manager = $manager;
    }

    /**
     * Execute the console command.
     * @return mixed
     * @throws Exception
     */
    public function handle()
    {
        // Flush migration cache.
        \Cache::tags([Migration::$cacheTag])
              ->flush();
        $migrationName = $this->option('migration');
        $migrationsData = $migrationName ? $this->manager->findMigrationByName($migrationName) : $this->manager->getMigrations();

        // Normalize to array.
        if (!is_array($migrationsData)) {
            $migrationsData = [$migrationsData];
        }

        if (!$count = count($migrationsData)) {
            $this->warn('No migration(s) found');

            return false;
        }

        $this->addCustomValues();

        foreach ($migrationsData as $name => $migrationClass) {
            $migrationInstance = $this->manager->getMigrationInstance($migrationClass);

            if ($migrationInstance instanceof Migration) {
                $migrationInstance->setTestMode((bool)$this->option('test'));
            }

            $this->info(PHP_EOL .
                '***' . PHP_EOL .
                '*** Starting migration of ' . $name . PHP_EOL .
                '***' . PHP_EOL
            );

            if ($migrationInstance instanceof UsesProgressBar && !$this->option('no-progress')) {
                $innerProgress = $this->output->createProgressBar($migrationInstance->getRecordsCount());
                $migrationInstance->setProgressBar($innerProgress);
            } else {
                if (property_exists($migrationInstance, 'withProgressBar')) {
                    $migrationInstance->withProgressBar = false;
                }
            }

            try {
                $migrationInstance->migrate();
            } catch (\Exception $exc) {
                throw new Exception('Migration failed: ' . $migrationClass, 0, $exc);
            }
            if ($migrationInstance instanceof UsesProgressBar) {
                $migrationInstance->getProgressBar()
                                  ->finish();
                $this->info(PHP_EOL);
            }

        }

        $this->info("\n" . 'Migration successful!');

        return true;
    }

    /**
     * Adds custom arguments to the manager
     */
    protected function addCustomValues()
    {
        foreach ($this->argument('values') as $argument) {
            if (strstr($argument, '=') !== false) {
                list($key, $value) = explode('=', $argument);
                $this->manager->addGlobalValue($key, $value);
            }
        }
    }
}
