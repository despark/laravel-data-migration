<?php


namespace Despark\Migrations\Traits;


trait WithHash
{
    /**
     * @param $value
     *
     * @return string
     */
    public function hash($value)
    {
        if (is_string($value)) {
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