<?php

namespace App\Core\Monitoring;

class TransactionMonitor
{
    private MetricsCollector $metrics;
    private PerformanceAnalyzer $analyzer;
    private AlertSystem $alerts;

    public function monitorTransaction(CriticalTransaction $transaction): void
    {
        $startTime = microtime(true);
        
        try {
            $this->startMonitoring($transaction);
            $metrics = $this->collectMetrics($transaction);
            $this->analyzePerformance($metrics);
            
        } finally {
            $this->recordTransactionTime($transaction, $startTime);
        }
    }

    private function startMonitoring(CriticalTransaction $transaction): void
    {
        $this->metrics->startCollection($transaction->getId());
        $this->analyzer->initializeAnalysis();
    }

    private function collectMetrics(CriticalTransaction $transaction): array
    {
        return [
            'execution_time' => $this->metrics->getExecutionTime(),
            'memory_usage' => $this->metrics->getMemoryUsage(),
            'cpu_usage' => $this->metrics->getCpuUsage(),
            'io_operations' => $this->metrics->getIOMetrics()
        ];
    }

    private function analyzePerformance(array $metrics): void
    {
        if (!$this->analyzer->meetsThresholds($metrics)) {
            $this->alerts->triggerPerformanceAlert($metrics);
        }
    }
}
