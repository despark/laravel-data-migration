<?php


namespace Despark\Migrations\Contracts;


use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Interface UsesProgressBar
 * @package Despark\Migrations\Contracts
 */
interface UsesProgressBar
{
    /**
     * @return ProgressBar
     */
    public function getProgressBar(): ProgressBar;

    /**
     * @param ProgressBar $progressBar
     * @return mixed
     */
    public function setProgressBar(ProgressBar $progressBar);

    /**
     * @return int
     */
    public function getRecordsCount(): int;
}