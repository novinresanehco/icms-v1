<?php

namespace App\Core\Notification\Analytics\Events;

use App\Core\Notification\Analytics\Models\ProcessingResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a batch is processed
 */
class BatchProcessedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @var string
     */
    public string $processor