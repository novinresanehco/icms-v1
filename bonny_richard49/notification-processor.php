<?php

namespace App\Core\Notification\Analytics\Processor;

use App\Core\Notification\Analytics\Contracts\ProcessorInterface;
use App\Core\Notification\Analytics\Exceptions\ProcessorException;
use App\Core\Notification\Analytics\Models\ProcessingResult;
use App\Core\Notification\Analytics\Events\BatchProcessedEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * BatchProcessor handles bulk processing of notification analytics data
 * 
 * This class implements processing of notification analytics data in batches,
 * with support for multiple processors, metrics tracking, retry logic and
 * error handling.
 */
class BatchProcessor
{
    /**
     * @var array Registered processors
     */
    private array $processors = [];

    /**
     * @var array Processing metrics
     */
    private array $metrics = [];

    /**
     * @var array Processor configuration
     */
    private array $config;

    /**
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 1000,
            'timeout' => 30,
            'retry_attempts' => 3,
            'error_threshold' => 0.1
        ], $config);
    }

    /**
     * Register a new processor
     *
     * @param string $name Processor identifier
     * @param ProcessorInterface $processor Processor instance
     * @return void
     */
    public function addProcessor(string $name, ProcessorInterface $processor): void
    {
        $this->processors[$name] = $processor;
    }

    /**
     * Process a batch of data
     *
     * @param array $data Data to process
     * @param string $processorName Name of processor to use
     * @param array $options Processing options
     * @return ProcessingResult
     * @throws ProcessorException
     */
    public function process(array $data, string $processorName, array $options = []): ProcessingResult
    {
        $this->validateProcessor($processorName);
        $this->validateData($data);

        $options = array_merge($this->config, $options);
        $batches = $this->prepareBatches($data, $options['batch_size']);
        
        $result = new ProcessingResult();
        $processor = $this->processors[$processorName];

        foreach ($batches as $batch) {
            try {
                $batchResult = $this->processBatch($processor, $batch, $options);
                $result->merge($batchResult);
                
                $this->trackMetrics($processorName, $batchResult);
                $this->broadcastEvent($processorName, $batchResult);
                
            } catch (ProcessorException $e) {
                Log::error("Batch processing failed", [
                    'processor' => $processorName,
                    'error' => $e->getMessage()
                ]);
                
                if (!$this->handleError($e, $result)) {
                    throw $e;
                }
            }
        }

        return $result;
    }

    /**
     * Process a single batch with retry logic
     *
     * @param ProcessorInterface $processor
     * @param array $batch
     * @param array $options
     * @return ProcessingResult
     * @throws ProcessorException
     */
    private function processBatch(
        ProcessorInterface $processor,
        array $batch,
        array $options
    ): ProcessingResult {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $options['retry_attempts']) {
            try {
                return $processor->process($batch, $options);
            } catch (\Exception $e) {
                $lastException = $e;
                $attempts++;
                
                if ($attempts < $options['retry_attempts']) {
                    sleep(pow(2, $attempts)); // Exponential backoff
                }
            }
        }

        throw new ProcessorException(
            "Processing failed after {$attempts} attempts",
            0,
            $lastException
        );
    }

    /**
     * Prepare data into batches
     *
     * @param array $data
     * @param int $batchSize
     * @return array
     */
    private function prepareBatches(array $data, int $batchSize): array
    {
        return array_chunk($data, $batchSize);
    }

    /**
     * Handle processing error
     *
     * @param ProcessorException $error
     * @param ProcessingResult $result
     * @return bool Whether processing should continue
     */
    private function handleError(ProcessorException $error, ProcessingResult $result): bool
    {
        $result->addError($error);
        
        // Stop if error threshold exceeded
        $errorRate = $result->getErrorRate();
        if ($errorRate > $this->config['error_threshold']) {
            return false;
        }

        return true;
    }

    /**
     * Track processing metrics
     *
     * @param string $processor
     * @param ProcessingResult $result
     * @return void
     */
    private function trackMetrics(string $processor, ProcessingResult $result): void
    {
        if (!isset($this->metrics[$processor])) {
            $this->metrics[$processor] = [
                'processed' => 0,
                'failed' => 0,
                'time' => 0
            ];
        }

        $this->metrics[$processor]['processed'] += $result->getProcessedCount();
        $this->metrics[$processor]['failed'] += $result->getErrorCount();
        $this->metrics[$processor]['time'] += $result->getProcessingTime();
    }

    /**
     * Broadcast batch processed event
     *
     * @param string $processor
     * @param ProcessingResult $result
     * @return void
     */
    private function broadcastEvent(string $processor, ProcessingResult $result): void
    {
        Event::dispatch(new BatchProcessedEvent($processor, $result));
    }

    /**
     * Validate processor exists
     *
     * @param string $name
     * @throws ProcessorException
     */
    private function validateProcessor(string $name): void
    {
        if (!isset($this->processors[$name])) {
            throw new ProcessorException("Processor not found: {$name}");
        }
    }

    /**
     * Validate input data
     *
     * @param array $data
     * @throws ProcessorException
     */
    private function validateData(array $data): void
    {
        if (empty($data)) {
            throw new ProcessorException("No data provided for processing");
        }
    }

    /**
     * Get processing metrics
     *
     * @return array
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
