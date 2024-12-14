<?php

namespace App\Core\Database;

class QueryMonitor 
{
    private $metrics;
    private $logger;
    private $alerter;

    const SLOW_QUERY_THRESHOLD = 100; // ms

    public function trackQuery(string $sql, array $bindings): void
    {
        $start = microtime(true);
        
        try {
            // Execute query
            $result = DB::select($sql, $bindings);
            
            // Calculate duration
            $duration = (microtime(true) - $start) * 1000;
            
            // Log slow queries
            if ($duration > self::SLOW_QUERY_THRESHOLD) {
                $this->handleSlowQuery($sql, $duration);
            }
            
            // Record metrics
            $this->metrics->record([
                'query' => $this->sanitizeQuery($sql),
                'duration' => $duration,
                'timestamp' => time()
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->handleQueryFailure($sql, $e);
            throw $e;
        }
    }

    private function handleSlowQuery(string $sql, float $duration): void
    {
        $this->logger->warning('Slow query detected', [
            'query' => $this->sanitizeQuery($sql),
            'duration' => $duration,
            'threshold' => self::SLOW_QUERY_THRESHOLD
        ]);

        $this->alerter->slowQueryAlert($sql, $duration);
    }

    private function sanitizeQuery(string $sql): string
    {
        // Remove sensitive data before logging
        return preg_replace('/VALUES\s*\((.*?)\)/', 'VALUES (...)', $sql);
    }
}
