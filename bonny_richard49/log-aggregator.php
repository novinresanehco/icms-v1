<?php

namespace App\Core\Logging\Aggregation;

class LogAggregator implements AggregatorInterface
{
    private EventProcessor $processor;
    private AnalyticsEngine $analytics;
    private AggregationCache $cache;
    private MetricsCollector $metrics;
    private Config $config;

    public function __construct(
        EventProcessor $processor,
        AnalyticsEngine $analytics,
        AggregationCache $cache,
        MetricsCollector $metrics,
        Config $config
    ) {
        $this->processor = $processor;
        $this->analytics = $analytics;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function aggregate(LogEntry $entry): void
    {
        $startTime = microtime(true);

        try {
            // Process and enrich the log entry
            $enrichedEntry = $this->processor->process($entry);

            // Update real-time metrics
            $this->updateMetrics($enrichedEntry);

            // Perform analytics
            $this->performAnalytics($enrichedEntry);

            // Cache aggregated data
            $this->cacheAggregations($enrichedEntry);

            // Record processing time
            $this->recordProcessingTime($startTime);

        } catch (\Exception $e) {
            $this->handleAggregationError($entry, $e);
        }
    }

    public function batchAggregate(array $entries): BatchResult
    {
        $result = new BatchResult();
        $startTime = microtime(true);

        try {
            // Group entries by type for efficient processing
            $groupedEntries = $this->groupEntries($entries);

            // Process each group
            foreach ($groupedEntries as $type => $typeEntries) {
                $this->processBatch($type, $typeEntries, $result);
            }

            // Update batch metrics
            $this->updateBatchMetrics($result);

            return $result;

        } catch (\Exception $e) {
            $this->handleBatchError($entries, $e, $result);
            throw $e;
        } finally {
            $result->setDuration(microtime(true) - $startTime);
        }
    }

    protected function updateMetrics(EnrichedLogEntry $entry): void
    {
        // Update general metrics
        $this->metrics->increment('logs.processed');
        $this->metrics->increment("logs.level.{$entry->level}");

        // Update type-specific metrics
        if ($entry->type) {
            $this->metrics->increment("logs.type.{$entry->type}");
        }

        // Update size metrics
        $this->metrics->gauge('logs.size', $entry->size);

        // Update performance metrics if available
        if (isset($entry->context['performance'])) {
            $this->metrics->histogram(
                'logs.performance',
                $entry->context['performance']
            );
        }
    }

    protected function performAnalytics(EnrichedLogEntry $entry): void
    {
        // Perform real-time analysis
        $analysis = $this->analytics->analyze($entry);

        // Update trend data
        $this->updateTrends($entry, $analysis);

        // Check for anomalies
        if ($analysis->hasAnomalies()) {
            $this->handleAnomalies($entry, $analysis->getAnomalies());
        }

        // Update pattern recognition
        $this->updatePatterns($entry, $analysis);
    }

    protected function cacheAggregations(EnrichedLogEntry $entry): void
    {
        $cacheKey = $this->getCacheKey($entry);
        
        $this->cache->remember($cacheKey, function () use ($entry) {
            return [
                'count' => $this->incrementAggregateCount($entry),
                'last_seen' => now(),
                'levels' => $this->updateLevelCounts($entry),
                'size' => $this->updateSizeStats($entry),
                'patterns' => $this->updatePatternStats($entry)
            ];
        }, $this->config->get('logging.cache_ttl', 3600));
    }

    protected function groupEntries(array $entries): array
    {
        return collect($entries)
            ->groupBy(function ($entry) {
                return $entry->type ?? 'default';
            })
            ->toArray();
    }

    protected function processBatch(string $type, array $entries, BatchResult $result): void
    {
        $processor = $this->getProcessor($type);
        
        foreach ($entries as $entry) {
            try {
                $enrichedEntry = $processor->process($entry);
                $this->aggregate($enrichedEntry);
                $result->incrementSuccessCount();
            } catch (\Exception $e) {
                $result->incrementFailureCount();
                $result->addError($e->getMessage());
                
                if (!$this->shouldContinueOnError($type)) {
                    throw $e;
                }
            }
        }
    }

    protected function updateTrends(EnrichedLogEntry $entry, Analysis $analysis): void
    {
        $trends = $this->analytics->getTrends();
        
        // Update time-based trends
        $trends->updateTimeSeries($entry);
        
        // Update pattern-based trends
        if ($analysis->hasPatterns()) {
            $trends->updatePatterns($analysis->getPatterns());
        }
        
        // Update correlation trends
        if ($analysis->hasCorrelations()) {
            $trends->updateCorrelations($analysis->getCorrelations());
        }
    }

    protected function handleAnomalies(EnrichedLogEntry $entry, array $anomalies): void
    {
        foreach ($anomalies as $anomaly) {
            // Record anomaly
            $this->metrics->increment('logs.anomalies');
            
            // Log anomaly details
            $this->logger->warning('Log anomaly detected', [
                'entry_id' => $entry->id,
                'anomaly_type' => $anomaly->type,
                'confidence' => $anomaly->confidence,
                'details' => $anomaly->details
            ]);
            
            // Trigger alerts if configured
            if ($this->shouldAlertOnAnomaly($anomaly)) {
                $this->alertSystem->triggerAnomaly($entry, $anomaly);
            }
        }
    }

    protected function updatePatterns(EnrichedLogEntry $entry, Analysis $analysis): void
    {
        if ($patterns = $analysis->getPatterns()) {
            $this->analytics->updatePatternDatabase([
                'patterns' => $patterns,
                'timestamp' => now(),
                'confidence' => $analysis->getConfidence(),
                'metadata' => [
                    'entry_id' => $entry->id,
                    'level' => $entry->level,
                    'type' => $entry->type
                ]
            ]);
        }
    }

    protected function handleAggregationError(LogEntry $entry, \Exception $e): void
    {
        // Log the error
        $this->logger->error('Log aggregation failed', [
            'entry_id' => $entry->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Update error metrics
        $this->metrics->increment('logs.aggregation.errors');

        // Notify if critical
        if ($this->isCriticalError($e)) {
            $this->notifyAggregationFailure($entry, $e);
        }
    }

    protected function shouldContinueOnError(string $type): bool
    {
        return $this->config->get(
            "logging.aggregation.continue_on_error.{$type}",
            true
        );
    }

    protected function isCriticalError(\Exception $e): bool
    {
        return $e instanceof CriticalAggregationException ||
               $e instanceof StorageException ||
               $e instanceof DataCorruptionException;
    }

    protected function recordProcessingTime(float $startTime): void
    {
        $duration = (microtime(true) - $startTime) * 1000;
        $this->metrics->timing('logs.aggregation.duration', $duration);
    }
}
