<?php

namespace App\Core\Notification\Analytics\Models;

use App\Core\Notification\Analytics\Exceptions\ProcessorException;
use Carbon\Carbon;

/**
 * Processing result model
 */
class ProcessingResult
{
    /**
     * @var array Processed records
     */
    private array $processed = [];

    /**
     * @var array Failed records
     */
    private array $failed = [];

    /**
     * @var array Processing errors
     */
    private array $errors = [];

    /**
     * @var float Processing start time
     */
    private float $startTime;

    /**
     * @var float Processing end time
     */
    private float $endTime;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Add successfully processed record
     *
     * @param mixed $record
     * @return void
     */
    public function addProcessed($record): void
    {
        $this->processed[] = $record;
    }

    /**
     * Add failed record
     *
     * @param mixed $record
     * @return void
     */
    public function addFailed($record): void
    {
        $this->failed[] = $record;
    }

    /**
     * Add processing error
     *
     * @param ProcessorException $error
     * @return void
     */
    public function addError(ProcessorException $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * Merge another result into this one
     *
     * @param ProcessingResult $result
     * @return void
     */
    public function merge(ProcessingResult $result): void
    {
        $this->processed = array_merge($this->processed, $result->getProcessed());
        $this->failed = array_merge($this->failed, $result->getFailed());
        $this->errors = array_merge($this->errors, $result->getErrors());
    }

    /**
     * Complete processing
     *
     * @return void
     */
    public function complete(): void
    {
        $this->endTime = microtime(true);
    }

    /**
     * Get processed records
     *
     * @return array
     */
    public function getProcessed(): array
    {
        return $this->processed;
    }

    /**
     * Get failed records
     *
     * @return array
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * Get processing errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get count of processed records
     *
     * @return int
     */
    public function getProcessedCount(): int
    {
        return count($this->processed);
    }

    /**
     * Get count of failed records
     *
     * @return int
     */
    public function getFailedCount(): int
    {
        return count($this->failed);
    }

    /**
     * Get count of errors
     *
     * @return int
     */
    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    /**
     * Get processing time in seconds
     *
     * @return float
     */
    public function getProcessingTime(): float
    {
        return $this->endTime - $this->startTime;
    }

    /**
     * Get error rate
     *
     * @return float
     */
    public function getErrorRate(): float
    {
        $total = $this->getProcessedCount() + $this->getFailedCount();
        if ($total === 0) {
            return 0.0;
        }

        return $this->getFailedCount() / $total;
    }

    /**
     * Get result summary
     *
     * @return array
     */
    public function getSummary(): array
    {
        return [
            'processed_count' => $this->getProcessedCount(),
            'failed_count' => $this->getFailedCount(),
            'error_count' => $this->getErrorCount(),
            'processing_time' => $this->getProcessingTime(),
            'error_rate' => $this->getErrorRate(),
            'timestamp' => Carbon::now()->toDateTimeString()
        ];
    }
}
