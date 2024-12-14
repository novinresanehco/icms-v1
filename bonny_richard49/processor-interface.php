<?php

namespace App\Core\Notification\Analytics\Contracts;

use App\Core\Notification\Analytics\Models\ProcessingResult;

/**
 * Interface for notification analytics processors
 */
interface ProcessorInterface
{
    /**
     * Process a batch of notification data
     *
     * @param array $data Data to process
     * @param array $options Processing options
     * @return ProcessingResult
     */
    public function process(array $data, array $options = []): ProcessingResult;
}
