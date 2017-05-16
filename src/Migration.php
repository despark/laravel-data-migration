<?php

namespace Despark\Migrations;

use Carbon\Carbon;
use Config;
use DB;
use Despark\Migrations\Contracts\MigrationContract;
use Despark\Migrations\Contracts\MigrationManagerContract as Manager;
use Despark\Migrations\Contracts\UsesProgressBar;
use Despark\Migrations\Exceptions\MigrationException;
use Despark\Migrations\Traits\WithProgressBar;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Schema;

/**
 * Class Migration.
 */
abstract class Migration implements MigrationContract, UsesProgressBar
{

    use WithProgressBar;

    /**
     *
     */
    const IGNORE = '_IGNORE_';
    /**
     *
     */
    const IMAGE = '_IMAGE_';

    /**
     * @var string
     */
    protected $newTable;

    /**
     * @var string
     */
    protected $oldTable;

    /**
     * Old table primary key.
     *
     * @var string
     */
    protected $oldId;

    /**
     * Whenever we should save old id.
     *
     * @var bool
     */
    protected $saveOldId = true;

    /**
     * @var string new table primary key for old key
     */
    protected $newId = 'id';

    /**
     * @var string old id column for the new table
     */
    protected $localOldId = 'old_id';

    /**
     * @var int
     */
    protected $chunks = 1000;

    /**
     * @var int
     */
    protected $maxPlaceholders = 65535;

    /**
     * @var string
     */
    protected $oldDbConnection;

    /**
     * @var array
     */
    protected $constraints = [];

    /**
     * @var array
     */
    protected $map = [];

    /**
     * Keeps the original map as it was when instantiated.
     *
     * @var array
     */
    protected $origMap = [];

    /**
     * @var array
     */
    protected $defaultValues = [];

    /**
     * Relations on the old table.
     * This will automatically join on the old table (still we need some way to choose the join type)
     *
     * @var array
     */
    protected $oldRelations = [];

    /**
     * @var array
     * <code>
     * 'customers' => [
     *      'foreign' => 'customer_id',
     *      'old_foreign' => 'old_db_customer_id',
     *      'key' => 'id'
     *      'old_key' => 'id_tsys_customer'
     * ]
     * </code>
     */
    protected $newRelations = [];

    /**
     * Found relation values
     *
     * @var array
     */
    protected $foundRelations = [];

    /**
     * @var Manager
     */
    protected $manager;

    /**
     * @var Builder
     */
    protected $readQuery;

    /**
     * @var array
     */
    protected $oldColumns = [];

    /**
     * @var array
     */
    protected $newColumns;

    /**
     * @var bool
     */
    protected $checkIntegrity = true;

    /**
     * Do we have timestamps.
     *
     * @var bool
     */
    protected $timestamps = true;

    /**
     * Primary used for debugging. In this mode failed relations will be silently logged.
     *
     * @var bool
     */
    protected $strictMode = true;

    /**
     * In test mode the migration will delete any records in receiving database.
     * @var bool
     */
    public $testMode = false;

    /**
     * If not in strict mode we collect faile records.
     *
     * @var array
     */
    protected $failedRecords = [];

    /**
     * @var bool
     */
    protected $debugMode = false;

    /**
     * @var bool
     */
    protected $dryRun = false;

    /**
     * @var bool
     */
    protected $force = false;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * Global cache key.
     *
     * @var string
     */
    public static $cacheTag = 'migration';

    /**
     * @var mixed
     */
    protected $maxPacketSize;

    /**
     * @var array
     */
    protected $markedForReview = [];

    /**
     * @var int
     */
    protected $idBeforeMigration;

    /**
     * @var bool
     */
    protected $insertIgnoreMode = false;

    /**
     * @var bool
     */
    public $withProgressBar = true;

    /**
     * @var array
     */
    protected $nullableRelationColumns = [];

    /**
     * @return Builder
     */
    abstract protected function readQuery();

    /**
     * Migration constructor.
     *
     * @param Manager $manager
     *
     * @throws MigrationException
     */
    public function __construct(Manager $manager)
    {
        $this->manager = $manager;

        // Set default oldId.
        if (!$this->getOldId()) {
            throw new MigrationException('You must specify old primary id in class ' . get_class($this));
        }

        $this->origMap = $this->map;
    }

    /**
     * Setup method
     */
    public function setup()
    {
        $this->maxPacketSize = \Cache::remember('mysql_max_allowed_packet_size', 24 * 60, function () {
            return DB::select('SELECT @@max_allowed_packet as value')[0]->value;
        });

        $this->setMaxChunks();

        $this->prepareRelations();

        $this->buildMap();
    }

    /**
     * Migrate..
     */
    public function migrate()
    {
        $this->setup();
        //Prepare new table. This needs do be first as we need to create fields before checking the integrity.
        $this->prepareTable();

        // Check integrity of the import mappings.
        if ($this->checkIntegrity) {
            $errors = $this->validateColumnIntegrity();

            if (true !== $errors) {
                $migration = $this->manager->findMigrationByClass(get_class($this));
                echo PHP_EOL . 'Map integrity check failed for ' . key($migration) . PHP_EOL;
                dd($errors);
            }
        }

        // Get the read query
        $this->getReadQuery();

        //Apply global constraints
        $this->applyConstraints();

        // Adds default select columns from main table.
        $this->addSelectColumns();

        // Chance to act before query executes
        $this->beforeQuery($this->readQuery);

        // Remove old data.
        $this->truncate();

        // Act before migration happens.
        $this->beforeMigration();

        // Executes and chunks the read query.
        // Also writes results to the new database
        $this->executeReadQuery();

        // Give a chance for the implementing classes to act after migration.
        $this->afterMigration();
    }


    /**
     *
     */
    protected function executeReadQuery()
    {
        $this->readQuery->chunk($this->chunks, function ($data) {
            // Give chance implementing classes to act before we trigger write.
            $this->beforeWrite($data);

            // Exclude data already in database
            $this->excludeInserted($data);

            // Give chance implementing classes to prepare the data.
            $this->prepareData($data);

            // Build relation id values.
            // This builds the foreign key values looking into the related table new ids.
            $this->buildRelations($data);

            //Disable FK checks
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            $this->write($data);
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->logFailed();

            // Flush relations
            $this->foundRelations = [];
        });
    }

    /**
     * Logs failed migrations.
     */
    protected function logFailed()
    {
        if (!$this->strictMode && !empty($this->failedRecords)) {
            $insertData = [];
            // Find current migration
            $migration = $this->manager->findMigrationByClass(get_class($this));

            $now = Carbon::now();

            foreach ($this->failedRecords as $record) {
                $insertData[] = [
                    'migration'  => key($migration),
                    'item_id'    => $record->{$this->getOldId()},
                    'item'       => json_encode((array)$record),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $chunks = array_chunk($insertData, 100);
            foreach ($chunks as $data) {
                DB::table('failed_migrations')
                  ->insert($data);
            }
        }
    }

    /**
     * @param $data
     *
     * @return $this
     */
    protected function prepareData(&$data)
    {
        return $this;
    }

    /**
     * Before migration.
     *
     * @return $this
     */
    protected function beforeMigration()
    {
        return $this;
    }

    /**
     * Method after migration.
     *
     * @return $this
     */
    protected function afterMigration()
    {
        return $this;
    }

    /**
     *
     */
    protected function prepareTable()
    {
        if ($this->saveOldId && !Schema::hasColumn($this->newTable, $this->localOldId)) {
            Schema::table($this->newTable, function (Blueprint $table) {
                $table->unsignedInteger($this->localOldId)
                      ->nullable()
                      ->after($this->newId);
                $table->index($this->localOldId);
            });
            // Reset new columns as we have one new column.
            $this->newColumns = null;
        }
    }

    /**
     * @param array $data
     *
     * @return $this
     */
    protected function beforeWrite(&$data)
    {
        return $this;
    }

    /**
     * @param      $data
     * @param bool $recurse
     *
     * @return bool
     *
     * @throws \Exception
     */
    protected function write($data, $recurse = true)
    {
        $insert = [];

        $now = Carbon::now();

        if (empty($data)) {
            return false;
        }

        foreach ($data as $key => $item) {
            // Check to see if we don't have explicitly set id for the new record.
            // This is useful to create master records of certain kind.
            // We also check if the new id and the old id are not the same label
            if ($this->newId != $this->getOldId()) {
                if (isset($item->{$this->newId}) && $recurse) {
                    // Recurse to make just this insert.
                    $this->write([$item], false);
                    continue;
                }
            }

            if (isset($item->{$this->newId}) && $this->newId != $this->getOldId()) {
                $insert[$key][$this->newId] = $item->{$this->newId};
            }

            foreach ($this->map as $oldColumn => $newColumn) {
                if ($newColumn == self::IGNORE) {
                    continue;
                }
                // We don't insert primary key in the loop.
                if ($newColumn == $this->getNewId()) {
                    continue;
                }
                if ($newColumn == $this->getOldId()) {
                    // @todo wtf
                }
                if (property_exists($item, $oldColumn)) {
                    $insert[$key][$newColumn] = $item->{$oldColumn};

                    // Check if we have a default value for the new field, and if old value is null.
                    // If this is the case we set the default value.
                    if (!empty($this->defaultValues)) {
                        if (isset($this->defaultValues[$newColumn]) && is_null($item->{$oldColumn})) {
                            $insert[$key][$newColumn] = $this->defaultValues[$newColumn];
                        }
                    }
                }
            }
            // We need to get the foreign key value
            foreach ($this->newRelations as $table => $relations) {
                foreach ($relations as $relation) {
                    try {
                        $insert[$key][$relation['foreign']] = $this->findRelationKeyValue($item, $table, $relation);
                    } catch (MigrationException $exc) {
                        // We allow inserting migration as null if the column is in the nullable relation array.
                        if ($this->isNullableRelationColumn($table, $relation['foreign'])) {
                            $insert[$key][$relation['foreign']] = null;
                        } else {
                            // If we are not in strict mode, log error and allow import without broken relations.
                            if (!$this->strictMode) {
                                if (!isset($this->failedRecords[$item->{$this->getOldId()}])) {
                                    $this->failedRecords[$item->{$this->getOldId()}] = $item;
                                }
                                unset($insert[$key]);
                                break 2;
                            } else {
                                throw new \Exception($exc->getMessage());
                            }
                        }
                    }
                }
            }

            // Add timestamps if we need to.
            if ($this->timestamps) {
                if (!isset($insert[$key]['created_at'])) {
                    $insert[$key]['created_at'] = $now;
                }
                if (!isset($insert[$key]['updated_at'])) {
                    $insert[$key]['updated_at'] = $now;
                }
            }
            // Not used anymore
            //            $writeData[$key]['orig'] = $item;
            //            $writeData[$key]['insert'] = $insert[$key] ? $insert[$key] : null;
        }
        if (empty($insert)) {
            return false;
        }
        if (!$this->dryRun) {
            // Before we insert
            $this->beforeInsert($insert, $data);

            try {
                // Check if we need to chunk based on max_packet_size
                $insertSize = $this->getArrayByteSize($insert) + 400; // We add 400 bytes buffer

                if ($insertSize > $this->maxPacketSize) {
                    $parts = (int)ceil($insertSize / $this->maxPacketSize);
                    $chunks = floor(count($insert) / $parts);
                    $insertChunks = array_chunk($insert, $chunks, true);
                    foreach ($insertChunks as $ins) {
                        $this->insert($ins);
                    }
                } else {
                    $this->insert($insert);
                }
            } catch (\Exception $exc) {
                $message = 'Problem inserting migration data for '
                    . key($this->manager->findMigrationByClass(get_class($this))) . PHP_EOL .
                    '. Error:';

                throw new MigrationException($message . ' ' . $exc->getMessage());
            }
            // We trigger after insert method
            $this->afterInsert($insert, $data);

            // Let's process marked for review items
            $this->processMarkedForReview();
        } else {
            var_dump($insert);
        }

        if ($this->withProgressBar) {
            $this->getProgressBar()
                 ->advance(count($insert));
        }

        // Eventually free some memory
        unset($insert);
    }

    /**
     * @param $insert
     * @param $data
     *
     * @return $this
     */
    protected function beforeInsert(&$insert, $data)
    {
        return $this;
    }

    /**
     * @param $insert
     * @param $data
     *
     * @return $this
     */
    protected function afterInsert($insert, $data)
    {
        return $this;
    }

    /**
     * Excludes data that is already in database.
     *
     * @param $data
     */
    protected function excludeInserted(&$data)
    {
        $oldId = $this->getOldId();
        $localOldId = $this->localOldId;

        if (!isset($this->idBeforeMigration)) {
            $this->idBeforeMigration = $this->query()
                                            ->max($this->newId);
        }

        // Key data by id
        $newData = [];
        foreach ($data as $item) {
            $newData[$item->$oldId] = $item;
        }
        $data = $newData;

        // chunk the exclusion
        $q = $this->query();
        if ($this->idBeforeMigration) {
            $q->where($this->newId, '<=', $this->idBeforeMigration);
        }

        $q->chunk($this->chunks, function ($items) use (&$data, $localOldId) {
            $delete = [];
            foreach ($items as $item) {
                // If in test mode we will delete some recored
                if (isset($data[$item->$localOldId])) {
                    if ($this->isTestMode()) {
                        $delete[] = $item->$localOldId;
                    } else {
                        unset($data[$item->$localOldId]);
                    }
                }
            }
            if ($this->isTestMode() && $delete) {
                $this->query()->whereIn($localOldId, $delete);
            }
        });
    }

    /**
     * @return string
     */
    public function getNewId()
    {
        return $this->newId;
    }

    /**
     * @param Builder $query
     *
     * @return $this
     */
    protected function beforeQuery($query)
    {
        return $this;
    }

    /**
     * Truncates tables before the migration.
     */
    protected function truncate()
    {
        $constraints = $this->manager->getGlobalConstraints();
        if (!empty($constraints)) {
            foreach ($constraints as $alias => $field) {
                if ($field['table'] == $this->oldTable) {
                    // Delete only the constraint
                    // Get the mapped field
                    $newField = $this->map($field['field']);
                    $query = $this->query()
                                  ->where($newField, '=', $field['value']);
                    $query->delete();

                    return;
                }
            }
        }
        // Try local constraints
        if (!empty($this->constraints)) {
            foreach ($this->constraints as $constraint) {
                $relation = $this->getConstrainedRelation($constraint);
                if ($relation) {
                    $query = $this->query()
                                  ->where($relation['foreign'], '=', $constraint['value']);
                    $query->delete();

                    return;
                }
            }
        }
        // This shouldn't happen.
        //DB::table($this->newTable)->truncate();
    }

    /**
     * @return string
     */
    public function getOldDbConnection(): string
    {
        if (!isset($this->oldDbConnection)) {
            $this->oldDbConnection = $this->manager->getDatabaseConnection();
        }

        return $this->oldDbConnection;
    }

    /**
     * @param string $oldDbConnection
     */
    public function setOldDbConnection(string $oldDbConnection)
    {
        $this->oldDbConnection = $oldDbConnection;
    }

    /**
     * @param $constraint
     *
     * @return array
     */
    protected function getConstrainedRelation($constraint)
    {
        foreach ($this->newRelations as $table => $relations) {
            foreach ($relations as $relation) {
                if ($relation['old_key'] == $constraint['field']) {
                    return $relation;
                }
            }
        }
    }

    /**
     * @param $data
     *
     * @throws MigrationException
     */
    protected function buildRelations($data)
    {
        // Check to see if we can get the foreign value from constraints. For now deprecated.
        //        if ($this->constraints) {
        //            foreach ($this->constraints as $constraint) {
        //                foreach ($this->newRelations as $table => $relations) {
        //                    foreach ($relations as $relation) {
        //                        if ($constraint['table'] == $relations) {
        //                            foreach ($data as $item) {
        //                                $this->foundRelations[$item->{$this->getOldId()}][$table] = $constraint['value'];
        //                            }
        //                        }
        //
        //                        return;
        //                    }
        //                }
        //
        //            }
        //        }

        if ($this->newRelations) {
            foreach ($data as $key => $item) {
                // We need to get the foreign key value
                foreach ($this->newRelations as $table => $relations) {
                    // If we have multiple relations for the table process every one of them.
                    foreach ($relations as $relation) {
                        $oldForeign = $relation['old_foreign'];

                        $oldKey = $relation['old_key'];
                        $oldId = $this->getOldId();
                        // Build array of of foreign keys.
                        if (!array_key_exists($item->$oldId, $this->foundRelations) ||
                            !array_key_exists($table, $this->foundRelations[$item->$oldId]) ||
                            !array_key_exists($item->$oldKey, $this->foundRelations[$item->$oldId][$table])
                        ) {
                            $query = DB::table($table)
                                       ->where($oldForeign, '=', $item->$oldKey)
                                       ->limit(1);
                            try {
                                $value = $query->value($relation['key']);
                            } catch (\PDOException $exc) {
                                throw new MigrationException('Missing migration for table ' . $table .
                                    ' or problem with the query. Error: ' . $exc->getMessage());
                            }

                            // We have null value in the old table.
                            if (property_exists($item, $oldKey) && (is_null($item->$oldKey) || $item->$oldKey === 0)) {
                                // If we allow null values.
                                // We need to convert the null key to null string to keep it in the array.

                                $item->$oldKey = 'null';
                                $value = null;

                            }

                            $this->foundRelations[$item->$oldId][$table][$item->$oldKey] = $value;
                        }
                    }
                }
            }
        }
    }

    /**
     * Finds old foreign key value in already built ones.
     *
     * @param        $item
     * @param string $table Relation table
     * @param        $relation
     *
     * @return bool|int
     *
     * @throws MigrationException
     */
    protected function findRelationKeyValue($item, $table, $relation)
    {
        $oldId = $this->getOldId();
        $oldKey = $relation['old_key'];
        // Check to see if we have found a relation
        if ((isset($this->foundRelations[$item->$oldId], $this->foundRelations[$item->$oldId][$table])) &&
            (
                // Allow if we have value OR..
                isset($this->foundRelations[$item->$oldId][$table][$item->$oldKey]) ||
                // Check if the old key is "null" (changed by find relation) meaning we don't have a relation value.
                $item->$oldKey === 'null' && is_null($this->foundRelations[$item->$oldId][$table][$item->$oldKey])
            )
        ) {
            return $this->foundRelations[$item->$oldId][$table][$item->$oldKey];
            // If the migration is forced we need to try returning null for the relation.
        } elseif ($this->force) {
            return null;
        } else {
            throw new MigrationException('Foreign key value cannot be found for item with ID: ' . $item->$oldId .
                ' and KEY: ' . $oldKey . '(' . $item->$oldKey . '). Review migration model ' . get_class($this));
        }
    }

    /**
     * @return Builder
     */
    public function getReadQuery()
    {
        if (!$this->readQuery) {
            $this->readQuery = $this->readQuery();
        }

        return $this->readQuery;
    }

    /**
     * @return int
     */
    public function getRecordsCount(): int
    {
        $this->applyConstraints();

        return $this->getReadQuery()
                    ->count();
    }

    /**
     * @param $table
     *
     * @return Builder
     */
    public function oldQuery($table = null)
    {
        if (!$table) {
            $table = $this->oldTable;
        }

        return DB::connection($this->getOldDbConnection())
                 ->table($table);
    }

    /**
     * @return Builder
     */
    public function query($table = null)
    {
        if (!$table) {
            $table = $this->newTable;
        }

        return DB::table($table);
    }

    /**
     * Apply query constraints.
     */
    protected function applyConstraints()
    {
        if (!$this->readQuery) {
            $this->getReadQuery();
        }
        $constraints = $this->manager->getGlobalConstraints();

        if (!empty($constraints)) {
            foreach ($constraints as $alias => $field) {
                // First check if we are not working on the current table and apply where, if it is.
                if ($field['table'] == $this->oldTable) {
                    $this->readQuery->where($alias, '=', $field['value']);
                    continue;
                }

                $localConstraints = $field['local'];

                // Join the table and apply constraint
                $relationAlias = $this->getRelationAlias($field['table']);
                $this->readQuery->join($field['table'], $alias, '=', $relationAlias);
                $this->readQuery->where($alias, '=', $field['value']);
                $this->readQuery->addSelect($relationAlias . ' as ' . $localConstraints['field']);

                // Try to find local value of the constraint
                $localId = DB::table($localConstraints['table'])
                             ->where($localConstraints['field'], '=', $localConstraints['value'])
                             ->limit(1)
                             ->value($localConstraints['primary_key']);

                // If we have our local id add it to the local constraints.
                if ($localId) {
                    $localConstraints['value'] = $localId;
                    $localConstraints['field'] = $localConstraints['primary_key'];
                    unset($localConstraints['primary_key']);
                    // Adds local constraints
                    $this->constraints[] = $localConstraints;
                }
            }
        }
    }

    /**
     * Adds default select columns.
     */
    protected function addSelectColumns()
    {
        // Select only fields on main table.
        foreach ($this->getOldColumns() as $column) {
            $this->readQuery->addSelect($this->oldTable . '.' . $column);
        }
    }

    /**
     * @param $table
     */
    protected function getRelationField($table)
    {
        if (array_key_exists($table, $this->oldRelations)) {
            return $this->oldRelations[$table];
        }
    }

    /**
     * @param $table
     *
     * @return string
     */
    protected function getRelationAlias($table)
    {
        if ($field = $this->getRelationField($table)) {
            return $this->oldTable . '.' . $field;
        }
    }

    /**
     * Build default map for tables.
     */
    protected function buildMap()
    {
        // Add new and old ids if we use it
        if ($this->saveOldId && $this->getOldId() && $this->localOldId) {
            if (!array_key_exists($this->getOldId(), $this->map)) {
                $this->map[$this->getOldId()] = $this->localOldId;
            }
        }

        // Add relations
        foreach ($this->newRelations as $table => $relations) {
            foreach ($relations as $relation) {
                if (!array_key_exists($relation['old_key'], $this->map)) {
                    $this->map[$relation['old_key']] = $relation['foreign'];
                }
            }
        }

        $columns = $this->getOldColumns();
        $pattern = '/_' . $this->oldTable . '|' . $this->oldTable . '_/';
        foreach ($columns as $column) {
            // We skip check for the id.
            if ($column == $this->getOldId()) {
                continue;
            }
            if (!array_key_exists($column, $this->map)) {
                $this->map[$column] = preg_replace($pattern, '', $column);
            }
        }
    }

    /**
     * Prepare the new relations array to be multidimensional as the migrration is expecting this.
     */
    protected function prepareRelations()
    {
        foreach ($this->newRelations as $table => $relations) {
            if (!is_int(key($relations))) {
                $this->newRelations[$table] = [$relations];
            }
        }
    }

    /**
     * @return Manager
     */
    public function getMigrationManager()
    {
        return $this->manager;
    }

    /**
     * @return int
     */
    public function getChunks()
    {
        return $this->chunks;
    }

    /**
     * Maps old columns to new one.
     *
     * @param $oldColumn
     *
     * @return mixed
     */
    public function map($oldColumn)
    {
        return isset($this->map[$oldColumn]) ? $this->map[$oldColumn] : null;
    }

    /**
     * @param null $table
     *
     * @return array
     * @throws \Despark\Migrations\Exceptions\MigrationException
     */
    protected function getOldColumns($table = null): array
    {
        if (is_null($table)) {
            $table = $this->oldTable;
        }
        $connection = DB::connection($this->getOldDbConnection());
        $dbExists = $connection->select('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?',
            [$connection->getDatabaseName(),]
        );

        if (!isset($this->oldColumns[$table]) && !empty($dbExists)) {
            $this->oldColumns[$table] = Schema::setConnection($connection)
                                              ->getColumnListing($table);
        }

        if (empty($this->oldColumns[$table])) {
            throw new MigrationException('Cannot get columns for table ' . $table);
        }


        return $this->oldColumns[$table];
    }

    /**
     * @param null $table
     *
     * @return array
     */
    protected function getNewColumns($table = null)
    {
        if (is_null($table)) {
            $table = $this->newTable;
        }
        if (!isset($this->newColumns[$table])) {
            $this->newColumns[$table] = Schema::getColumnListing($table);
        }

        return $this->newColumns[$table] ?? null;
    }


    /**
     * Get ambitious old id to use in join cases.
     *
     * @return string
     */
    public function getOldIdAmbitious()
    {
        return $this->oldTable . '.' . $this->getOldId();
    }

    /**
     * @return array|bool
     */
    protected function validateColumnIntegrity()
    {
        $oldColumns = $this->getOldColumns();
        $newColumns = $this->getNewColumns();

        $errors = [];
        foreach ($this->map as $old => $new) {
            if ($new == self::IGNORE) {
                $key = array_search($old, $oldColumns);
                if (false !== $key) {
                    unset($oldColumns[$key]);
                }
                continue;
            }
            $mapped = $this->map($old);
            if (!in_array($mapped, $newColumns)) {
                $errors[$old] = $new;
            } else {
                // Remove it from array for performance gain
                $key = array_search($mapped, $newColumns);
                unset($newColumns[$key]);
            }
        }

        if (empty($errors)) {
            return true;
        }

        return $errors;
    }

    /**
     * @return string
     */
    public function getNewTable(): string
    {
        return $this->newTable;
    }

    /**
     * @return string
     */
    public function getOldTable(): string
    {
        return $this->oldTable;
    }

    /**
     * @return bool
     */
    public function isSaveOldId()
    {
        return $this->saveOldId;
    }

    /**
     * @return string
     */
    public function getLocalOldId()
    {
        return $this->localOldId;
    }

    /**
     * @return mixed
     */
    public function getDefaultDatabase()
    {
        return Config::get('database.connections.' . Config::get('database.default') . '.database');
    }

    /**
     * @return array
     */
    public function getNullableRelationColumns(): array
    {
        return $this->nullableRelationColumns;
    }

    /**
     * @param array $nullableRelationColumns
     *
     * @return Migration
     */
    public function setNullableRelationColumns(array $nullableRelationColumns)
    {
        $this->nullableRelationColumns = $nullableRelationColumns;

        return $this;
    }

    /**
     * @param string $column
     *
     * @return bool
     */
    public function isNullableRelationColumn(string $table, string $column): bool
    {
        $nullableColumns = $this->getNullableRelationColumns();
        if (array_key_exists($table, $nullableColumns)) {
            return in_array($column, $nullableColumns[$table]);
        }

        return false;
    }


    /**
     * Sets max chunks possible for sql prepared statements.
     */
    public function setMaxChunks()
    {
        $numColumns = DB::select('SELECT COUNT(*) AS col_count FROM INFORMATION_SCHEMA.COLUMNS ' .
            'WHERE table_schema = ? AND table_name = ?',
            [$this->getDefaultDatabase(), $this->newTable])[0]->col_count;
        if (!$numColumns) {
            throw new MigrationException('No columns for table ' . $this->newTable);
        }
        $this->chunks = floor($this->maxPlaceholders / $numColumns);
    }

    /**
     * @return bool
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * @param bool $testMode
     *
     * @return Migration
     */
    public function setTestMode(bool $testMode)
    {
        $this->testMode = $testMode;

        return $this;
    }


    /**
     * @param $data
     */
    public function log($data)
    {
        if (!$this->logger) {
            $this->logger = new Logger('Migration logger');
            $this->logger->pushHandler(new StreamHandler(storage_path('logs/migrations.log')));
        }
        if (!is_string($data) || !is_int($data)) {
            $dump = var_export($data, true);
        } else {
            $dump = $data;
        }
        $this->logger->alert($dump);
    }

    /**
     * @param          $item
     * @param callable $callback
     *
     * @return mixed
     */
    public function cache($item, callable $callback)
    {
        if (!$value = \Cache::tags([self::$cacheTag])
                            ->get($item)
        ) {
            $value = call_user_func($callback, $this);
            \Cache::tags([self::$cacheTag])
                  ->put($item, $value, 1);
        }

        return $value;
    }

    /**
     * @param $array
     *
     * @return int
     */
    public function getArrayByteSize($array)
    {
        $serialized = serialize($array);
        if (function_exists('mb_strlen')) {
            return mb_strlen($serialized, '8bit');
        } else {
            return strlen($serialized);
        }
    }

    /**
     * Create table for items for review.
     *
     * @param int $recordId Old table record id
     * @param array|string $fieldsForReview Fields that need to be reviewed
     * @param bool $local
     * @param string $comment Additional comment
     */
    public function markForReview($recordId, $fieldsForReview, $comment = null, $local = true)
    {
        if (!is_array($fieldsForReview)) {
            $fieldsForReview = [$fieldsForReview];
        }
        $this->markedForReview[$recordId][] = [
            'local'         => $local,
            'review_id'     => $recordId,
            'review_fields' => $fieldsForReview,
            'comment'       => $comment,
        ];
    }

    /**
     *
     */
    protected function processMarkedForReview()
    {
        if (empty($this->markedForReview)) {
            return;
        }
        $collection = collect($this->markedForReview);

        $oldTable = $this->oldTable . '_reviews';
        $hasRemoteTable = Schema::connection($this->getOldDbConnection())
                                ->hasTable($oldTable);

        $newTable = $this->newTable . '_reviews';
        $hasLocalTable = Schema::hasTable($newTable);

        $hasLocal = false;
        $hasRemote = false;

        // Check if we have local or remote to create;
        if (!$hasRemoteTable || !$hasLocalTable) {
            foreach ($collection as $item) {
                foreach ($item as $values) {
                    if ($values['local'] === true) {
                        $hasLocal = true;
                    } else {
                        $hasRemote = true;
                    }
                    if ($hasLocal && $hasRemote) {
                        break 2;
                    }
                }
            }
        }

        if ($hasRemote) {
            Schema::connection($this->getOldDbConnection())
                  ->create($oldTable, function (Blueprint $table) {
                      $table->increments('id');
                      $table->unsignedInteger('review_id');
                      $table->text('review_fields');
                      $table->string('comment')
                            ->nullable();
                  });
        }

        if ($hasLocal) {
            Schema::create($newTable, function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('review_id');
                $table->text('review_fields');
                $table->string('comment')
                      ->nullable();
            });
        }

        $chunks = $collection->chunk($this->chunks);

        $newTableInserts = [];
        $oldTableInserts = [];
        foreach ($chunks as $key => $data) {
            foreach ($data as $recordId => $items) {
                foreach ($items as $itemData) {
                    $itemData['review_fields'] = json_encode($itemData['review_fields']);
                    if ($itemData['local']) {
                        $newTableInserts[] = array_except($itemData, 'local');
                    } else {
                        $oldTableInserts[] = array_except($itemData, 'local');
                    }
                }
                unset($this->markedForReview[$recordId]);
            }
        }

        if (!empty($newTableInserts)) {
            $this->query($newTable)
                 ->insert($newTableInserts);
        }
        if (!empty($oldTableInserts)) {
            $this->oldQuery($oldTable)
                 ->insert($oldTableInserts);
        }
    }

    /**
     * Override defaut insert method to allow insert ignore for mysql
     * Insert a new record into the database.
     *
     * @param array $values
     *
     * @return bool
     */
    public function insert(array $values)
    {
        if ($this->insertIgnoreMode) {
            if (empty($values)) {
                return true;
            }

            // Since every insert gets treated like a batch insert, we will make sure the
            // bindings are structured in a way that is convenient for building these
            // inserts statements by verifying the elements are actually an array.
            if (!is_array(reset($values))) {
                $values = [$values];
            }

            // Since every insert gets treated like a batch insert, we will make sure the
            // bindings are structured in a way that is convenient for building these
            // inserts statements by verifying the elements are actually an array.
            else {
                foreach ($values as $key => $value) {
                    ksort($value);
                    $values[$key] = $value;
                }
            }

            // We'll treat every insert like a batch insert so we can easily insert each
            // of the records into the database consistently. This will make it much
            // easier on the grammars to just handle one type of record insertion.
            $bindings = [];

            foreach ($values as $record) {
                foreach ($record as $value) {
                    $bindings[] = $value;
                }
            }
            $query = $this->query();
            $grammar = $query->getGrammar();
            $sql = $grammar->compileInsert($query, $values);

            // We will hack it a little bit to make insert ignore instead of simple insert
            // Not using str_replace so we can only replace the first occurence.
            $pos = strpos($sql, 'INSERT INTO ');
            if ($pos !== false) {
                $sql = substr_replace($sql, 'INSERT IGNORE INTO ', $pos, strlen('INSERT INTO '));
            }

            // Once we have compiled the insert statement's SQL we can execute it on the
            // connection and return a result as a boolean success indicator as that
            // is the same type of result returned by the raw connection instance.
            $bindings = $this->cleanBindings($bindings);

            return $query->getConnection()
                         ->insert($sql, $bindings);
        } else {
            return $this->query()
                        ->insert($values);
        }
    }

    /**
     * @param array $bindings
     *
     * @return array
     */
    protected function cleanBindings(array $bindings)
    {
        return array_values(array_filter($bindings, function ($binding) {
            return !$binding instanceof Expression;
        }));
    }

    /**
     * @return array
     */
    public function getMap(): array
    {
        return $this->map;
    }

    /**
     * @param array $map
     *
     * @return Migration
     */
    public function setMap(array $map)
    {
        $this->map = $map;

        return $this;
    }

    /**
     * @return string
     */
    public function getOldId(): string
    {
        return $this->oldId;
    }


}
