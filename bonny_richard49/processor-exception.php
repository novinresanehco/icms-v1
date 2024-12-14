<?php

namespace App\Core\Notification\Analytics\Exceptions;

use Exception;

/**
 * Exception class for processor errors
 */
class ProcessorException extends Exception
{
    /**
     * @var mixed Failed data
     */
    protected $failedData;

    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param mixed $failedData
     */
    public function __construct(
        string $message = "", 
        int $code = 0,
        \Throwable $previous = null,
        $failedData = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->failedData = $failedData;
    }

    /**
     * Get the failed data
     *
     * @return mixed
     */
    public function getFailedData()
    {
        return $this->failedData;
    }
}
