<?php


namespace Despark\Migrations\Traits;


use Symfony\Component\Console\Helper\ProgressBar;

trait WithProgressBar
{

    /**
     * @var ProgressBar
     */
    protected $progressBar;

    /**
     * @return ProgressBar
     */
    public function getProgressBar(): ProgressBar
    {
        return $this->progressBar;
    }

    /**
     * @param ProgressBar $progressBar
     * @return WithProgressBar
     */
    public function setProgressBar(ProgressBar $progressBar)
    {
        $this->progressBar = $progressBar;

        return $this;
    }


}