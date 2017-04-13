<?php


namespace Despark\Migrations\Traits;


use Illuminate\Database\Schema\Blueprint;

/**
 * Class HashesOldId.
 */
trait HashesOldId
{

    /**
     * @return array
     */
    abstract public function getHashColumns(): array;

    /**
     * Prepare migration table
     */
    protected function prepareTable()
    {
        if ($this->isSaveOldId()) {

            $change = false;
            if ($exists = \Schema::hasColumn($this->getNewTable(), $this->getLocalOldId())) {
                $change = \DB::connection()
                             ->getDoctrineColumn($this->getNewTable(), $this->getLocalOldId())
                             ->getType()
                             ->getName() !== 'string';
            }
            if (!$exists || $change) {
                $this->createOldIdColumn($change);
            }
            // if we have existing records we need to hash them
            if ($change) {
                $query = \DB::table($this->getNewTable())->whereNotNull($this->getLocalOldId());
                $query->chunk(5000, function ($data) {
                    $newIdKey = $this->getNewId();
                    $localOldId = $this->getLocalOldId();
                    foreach ($data as $item) {
                        \DB::table($this->getNewTable())
                           ->where($newIdKey, $item->$newIdKey)
                           ->update([$localOldId => $this->hash($item->$localOldId)]);
                    }
                });
            }
        }
    }


    /**
     * @param bool $change
     */
    private function createOldIdColumn($change = false)
    {
        \Schema::table($this->getNewTable(), function (Blueprint $table) use ($change) {
            $newId = $this->getNewId();
            if (is_array($newId)) {
                $after = last($newId);
            } else {
                $after = $newId;
            }
            $field = $table->addColumn('string', $this->getLocalOldId(), [
                'length'   => 40,
                'fixed'    => true,
                'nullable' => true,
                'after'    => $after,
            ]);

            if ($change) {
                $field->change();
            } else {
                $table->index($this->getLocalOldId());
            }
        });
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

        //        if (! isset($this->idBeforeMigration)) {
        //            $this->idBeforeMigration = $this->query()->max($this->newId);
        //        }

        // Key data by id
        $newData = [];
        foreach ($data as $item) {
            $newData[$item->$oldId] = $item;
        }
        $data = $newData;

        // chunk the exclusion
        $this->query()
            // ->where($this->newId, '<=', $this->idBeforeMigration)
             ->chunk($this->chunks, function ($items) use (&$data, $localOldId) {
                foreach ($items as $item) {
                    if (isset($data[$item->$localOldId])) {
                        unset($data[$item->$localOldId]);
                    }
                }
            });
    }


    /**
     * @param array $data
     *
     * @return $this
     */
    protected function beforeWrite(&$data)
    {
        foreach ($data as &$item) {
            $forHash = [];
            foreach ($this->getHashColumns() as $column) {
                if (is_callable($column)) {
                    $forHash[] = call_user_func($column);
                } elseif (property_exists($item, $column)) {
                    $forHash[] = $item->$column;
                }
            }
            $item->hash = $this->hash($forHash);
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getOldId(): string
    {
        return 'hash';
    }

    /**
     * @param $value
     *
     * @return string
     */
    public function hash($value)
    {
        if (is_string($value)) {
            dd(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
            $value = [$value];
        } elseif (is_object($value)) {
            $value = (array)$value;
        }

        if (is_array($value)) {
            $value = json_encode($value);
        }

        return sha1($value);
    }

}