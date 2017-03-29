<?php


namespace Despark\Migrations\Contracts;


/**
 * Interface MigrationManagerContract
 * @package Despark\Migrations\Contracts
 */
interface MigrationManagerContract
{

    /**
     * @param $name
     * @param $class
     * @return void
     */
    public function addMigration($name, $class);

    /**
     * @return mixed
     */
    public function getMigrations();

    /**
     * @return string
     */
    public function getMigration($name);

    /**
     * @param $name
     * @return MigrationContract
     */
    public function getMigrationInstance($name);

    /**
     * @param $class
     * @return mixed
     */
    public function findMigrationByClass($class);

    /**
     * @param $name
     * @return mixed
     */
    public function findMigrationByName($name);

    /**
     * @param $key
     * @param $value
     * @return mixed
     */
    public function addGlobalValue($key, $value);

    /**
     * @param      $key
     * @param null $default
     * @return mixed
     */
    public function getGlobalValue($key, $default = null);

}