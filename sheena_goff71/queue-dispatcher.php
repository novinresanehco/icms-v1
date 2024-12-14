<?php

namespace App\Core\Queue\Services;

use App\Core\Queue\Models\QueueJob;
use App\Core\Queue\Handlers\JobHandlerFactory;

class QueueDispatcher
{
    public function __construct(private JobHandlerFactory $handlerFactory)
    {
    }

    public function dispatch(QueueJob $job): void
    {
        if (!$job->shouldProcess()) {
            return;
        }

        if ($job->delay) {
            $this->dispatchLater($job);
            return;
        }

        dispatch(function () use ($job) {
            $this->processJob($job);
        })->onQueue($job->queue);
    }

    protected function dispatchLater(QueueJob $job): void
    {
        dispatch(function () use ($job) {
            $this->processJob($job);
        })
        ->delay($job->delay)
        ->onQueue($job->queue);
    }

    protected function processJob(QueueJob $job): void
    {
        try {
            $job->markAsStarted();
            
            $handler = $this->handlerFactory->create($job->type);
            $result = $handler->handle($job->data);
            
            $job->markAsCompleted($result);
        } catch (\Exception $e) {
            $this->handleJobFailure($job, $e);
        }
    }

    protected function handleJobFailure(QueueJob $job, \Exception $e): void
    {
        $job->markAsFailed($e->getMessage());

        if ($job->canRetry()) {
            $this->dispatch($job);
        }

        logger()->error('Queue job failed', [
            'job_id' => $job->id,
            'type' => $job->type,
            'error' => $e->getMessage()
        ]);
    }
}
