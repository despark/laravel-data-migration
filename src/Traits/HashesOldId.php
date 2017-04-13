<?php


namespace Despark\Migrations\Traits;


use Illuminate\Database\Schema\Blueprint;

/**
 * Class HashesOldId.
 */
trait HashesOldId
{

    use WithHash;

    /**
     * @param \stdClass $item
     *
     * @return array
     */
    abstract public function getHashColumns(\stdClass $item): array;

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
        }
    }


    /**
     * @param bool $change
     */
    protected function createOldIdColumn($change = false)
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

        // Key data by id
        $newData = [];
        foreach ($data as $item) {
            $newData[$item->$oldId] = $item;
        }
        $data = $newData;

        // chunk the exclusion
        $q = $this->query();

        $q->chunk($this->chunks, function ($items) use (&$data, $localOldId) {
            $delete = [];
            foreach ($items as $item) {
                // If in test mode we will delete some records
                if (isset($data[$item->$localOldId])) {
                    if ($this->testMode) {
                        $delete[] = $item->$localOldId;
                    } else {
                        unset($data[$item->$localOldId]);
                    }
                }
            }
            if ($this->testMode && $delete) {
                $this->query()->whereIn($localOldId, $delete)->delete();
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
            $item->hash = $this->hash($this->getHashColumns($item));
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
}