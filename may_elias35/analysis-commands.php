<?php

namespace App\Core\Audit\Commands;

class RunAnalysisCommand
{
    private AnalysisService $service;
    private ValidatorInterface $validator;
    private LoggerInterface $logger;

    public function __construct(
        AnalysisService $service,
        ValidatorInterface $validator,
        LoggerInterface $logger
    ) {
        $this->service = $service;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    public function execute(array $input): void
    {
        try {
            $this->validate($input);
            
            $result = $this->service->analyze($input);
            
            $this->logger->info('Analysis completed', [
                'analysis_id' => $result->getId(),
                'metrics' => $result->getMetrics()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Analysis failed', [
                'error' => $e->getMessage(),
                'input' => $input
            ]);
            throw $e;
        }
    }

    private function validate(array $input): void
    {
        $result = $this->validator->validate($input);
        if (!$result->isValid()) {
            throw new ValidationException($result->getErrors());
        }
    }
}

class GenerateReportCommand
{
    private ReportService $service;
    private array $config;

    public function __construct(ReportService $service, array $config = [])
    {
        $this->service = $service;
        $this->config = $config;
    }

    public function execute(string $type, array $params = []): Report
    {
        return $this->service->generateReport($type, $params, $this->config);
    }
}

class ProcessBatchCommand
{
    private BatchProcessor $processor;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(
        BatchProcessor $processor,
        MetricsCollector $metrics,
        LoggerInterface $logger
    ) {
        $this->processor = $processor;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function execute(array $batch): void
    {
        $startTime = microtime(true);
        
        try {
            $result = $this->processor->process($batch);
            
            $this->metrics->increment('batch_processed');
            $this->metrics->gauge('batch_size', count($batch));
            $this->metrics->timing(
                'batch_processing_time',
                (microtime(true) - $startTime) * 1000
            );
            
            $this->logger->info('Batch processed', [
                'size' => count($batch),
                'result' => $result
            ]);
        } catch (\Exception $e) {
            $this->metrics->increment('batch_errors');
            $this->logger->error('Batch processing failed', [
                'error' => $e->getMessage(),
                'batch_size' => count($batch)
            ]);
            throw $e;
        }
    }
}

class SendNotificationCommand
{
    private NotificationService $service;
    private ValidatorInterface $validator;
    private LoggerInterface $logger;

    public function __construct(
        NotificationService $service,
        ValidatorInterface $validator,
        LoggerInterface $logger
    ) {
        $this->service = $service;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    public function execute(Notification $notification): void
    {
        try {
            $this->validate($notification);
            
            $this->service->send($notification);
            
            $this->logger->info('Notification sent', [
                'type' => $notification->getType(),
                'recipients' => $notification->getRecipients()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Notification failed', [
                'error' => $e->getMessage(),
                'notification' => $notification
            ]);
            throw $e;
        }
    }

    private function validate(Notification $notification): void
    {
        $result = $this->validator->validate($notification);
        if (!$result->isValid()) {
            throw new ValidationException($result->getErrors());
        }
    }
}

class OptimizePerformanceCommand
{
    private PerformanceOptimizer $optimizer;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(
        PerformanceOptimizer $optimizer,
        MetricsCollector $metrics,
        LoggerInterface $logger
    ) {
        $this->optimizer = $optimizer;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        $startTime = microtime(true);
        
        try {
            $result = $this->optimizer->optimize();
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->metrics->timing('optimization_duration', $duration);
            $this->metrics->record('optimization_metrics', $result);
            
            $this->logger->info('Performance optimization completed', [
                'duration' => $duration,
                'metrics' => $result
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Performance optimization failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
