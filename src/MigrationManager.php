<?php


namespace Despark\Migrations;

use Despark\Migrations\Contracts\MigrationContract;
use Despark\Migrations\Contracts\MigrationManagerContract;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;


/**
 * Class Manager
 */
class MigrationManager implements MigrationManagerContract
{

    /**
     * @var array
     */
    protected $migrations;

    /**
     * @var array
     */
    protected $globalConstraint;

    /**
     * @var
     */
    protected $values;

    /**
     * @var string
     */
    protected $databaseConnection;


    /**
     * @param $name
     * @param $class
     *
     * @return MigrationManagerContract
     * @throws \Exception
     */
    public function addMigration($name, $class): MigrationManagerContract
    {
        $this->validateMigrationClass($class);

        $this->migrations[$name] = $class;

        return $this;
    }

    /**
     * @param $class
     *
     * @throws \Exception
     */
    protected function validateMigrationClass($class)
    {
        if (is_array($class)) {
            foreach ($class as $actualClass) {
                $this->validateMigrationClass($actualClass);
            }

            return;
        }
        if (!class_exists($class)) {
            throw new \Exception('Migration class ' . $class . ' not found');
        }

        $implementations = class_implements($class);
        if (!in_array(MigrationContract::class, $implementations)) {
            throw new \Exception('Migration class ' . $class . ' must implement ' . MigrationContract::class);
        }
    }

    /**
     * @return array
     */
    public function getMigrations()
    {
        return array_flatten($this->migrations);
    }

    /**
     * @return array
     */
    public function getRawMigrations()
    {
        return $this->migrations;
    }

    /**
     * @return string|array
     */
    public function getMigration($name)
    {

        return isset($this->migrations[$name]) ? $this->migrations[$name] : null;
    }

    /**
     * @param string $name
     *
     * @return MigrationContract
     */
    public function getMigrationInstance($class)
    {
        return app($class);
    }

    /**
     * @param $class
     *
     * @return array
     */
    public function findMigrationByClass($class)
    {
        foreach ($this->getMigrations() as $name => $migration) {
            if ($migration === $class) {
                return [$name => $migration];
            }
        }

        return [];
    }

    /**
     * @param $name
     *
     * @return array|string
     */
    public function findMigrationByName($name)
    {
        if (is_array($name)) {
            $name = array_unique($name);
            $migrations = [];
            foreach ($name as $actualName) {
                $migration = $this->findMigrationByName($actualName);
                if ($migration) {
                    $migrations = array_merge($migrations, $migration);
                }
            }

            return $migrations ?? null;
        }

        $iterator = new RecursiveArrayIterator($this->migrations);
        $recursive = new RecursiveIteratorIterator(
            $iterator,
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($recursive as $key => $class) {
            // We find the first key.
            if ($key === $name) {
                if (is_array($class)) {
                    dump($class);
                    return $class;
                }

                return [$key => $class];
            }
        }
    }

    /**
     * @param $table
     * @param $field
     * @param $value
     */
    public function addGlobalConstraint($table, $field, $value)
    {
        $key = $table . '.' . $field;
        if (!in_array($key, $this->globalConstraint)) {

            $this->globalConstraint[$key] = [
                'table' => $table,
                'field' => $field,
                'value' => $value,
                'local' => $this->getLocalConstraintData($table, $field, $value),
            ];
        }
    }

    /**
     * Gets local database data from migration model.
     *
     * @param $table
     * @param $field
     *
     * @return array
     */
    protected function getLocalConstraintData($table, $field, $value)
    {
        foreach ($this->getMigrations() as $prority => $migrations) {
            foreach ($migrations as $migration) {
                // Find
                $model = new $migration($this);
                if ($model->getOldTable() == $table) {
                    return [
                        'field'       => $model->map($field),
                        'value'       => $value,
                        'table'       => $model->getNewTable(),
                        'primary_key' => $model->getNewId(),
                    ];
                }
            }
        }
    }

    /**
     * @return array
     */
    public function getGlobalConstraints()
    {
        return $this->globalConstraint;
    }

    /**
     * @param $key
     * @param $value
     *
     * @return mixed|void
     */
    public function addGlobalValue($key, $value)
    {
        $this->values[$key] = $value;
    }

    /**
     * @param      $key
     * @param null $default
     *
     * @return mixed
     */
    public function getGlobalValue($key, $default = null)
    {
        return isset($this->values[$key]) ? $this->values[$key] : $default;
    }

    /**
     * @return mixed
     */
    public function getGlobalValues()
    {
        return $this->values;
    }

    /**
     * @return string
     */
    public function getDatabaseConnection(): string
    {
        return $this->databaseConnection;
    }

    /**
     * @param string $databaseConnection
     *
     * @return MigrationManagerContract
     */
    public function setDatabaseConnection(string $databaseConnection): MigrationManagerContract
    {
        $this->databaseConnection = $databaseConnection;

        return $this;
    }


}