<?php


namespace Despark\Migrations;

use Despark\Migrations\Contracts\MigrationManagerContract;
use Despark\Migrations\Contracts\MigrationContract;


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
     * @param $name
     * @param $class
     * @return void
     * @throws \Exception
     */
    public function addMigration($name, $class)
    {
        if (! class_exists($class)) {
            throw new \Exception('Migration class '.$class.' not found');
        }

        $implementations = class_implements($class);
        if (! in_array(MigrationContract::class, $implementations)) {
            throw new \Exception('Migration class '.$class.' must implement '.MigrationContract::class);
        }

        $this->migrations[$name] = $class;
    }

    /**
     * @return array
     */
    public function getMigrations()
    {
        return $this->migrations;
    }

    /**
     * @return string
     */
    public function getMigration($name)
    {
        return isset($this->migrations[$name]) ? $this->migrations[$name] : null;
    }

    /**
     * @param $name
     * @return MigrationContract
     */
    public function getMigrationInstance($name)
    {
        $migrationClass = $this->getMigration($name);

        return app($migrationClass);
    }

    /**
     * @param $class
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
     * @return mixed|null
     */
    public function findMigrationByName($name)
    {
        if (is_array($name)) {
            $migrations = [];
            foreach ($name as $n) {
                if (isset($this->getMigrations()[$n])) {
                    $migrations[$n] = $this->getMigrations()[$n];
                }
            }

            return $migrations;
        } else {
            return isset($this->getMigrations()[$name]) ? $this->getMigrations()[$name] : null;
        }
    }

    /**
     * @param $table
     * @param $field
     * @param $value
     */
    public function addGlobalConstraint($table, $field, $value)
    {
        $key = $table.'.'.$field;
        if (! in_array($key, $this->globalConstraint)) {

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
                        'field' => $model->map($field),
                        'value' => $value,
                        'table' => $model->getNewTable(),
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
     */
    public function addGlobalValue($key, $value)
    {
        $this->values[$key] = $value;
    }

    /**
     * @param      $key
     * @param null $default
     * @return mixed
     */
    public function getGlobalValue($key, $default = null)
    {
        return isset($this->values[$key]) ? $this->values[$key] : $default;
    }
}