<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\Cache;
use App\Core\Contracts\MetricsInterface;

class PerformanceAnalyzer
{
    private MetricsStore $store;
    private ThresholdManager $thresholds;
    private AlertService $alerts;
    
    private const CRITICAL_CPU_THRESHOLD = 70;
    private const CRITICAL_MEMORY_THRESHOLD = 80;
    private const CRITICAL_ERROR_RATE = 1.0;
    
    public function __construct(
        MetricsStore $store,
        ThresholdManager $thresholds,
        AlertService $alerts
    ) {
        $this->store = $store;
        $this->thresholds = $thresholds;
        $this->alerts = $alerts;
    }

    public function analyzeMetrics(array $metrics): AnalysisResult
    {
        DB::beginTransaction();
        
        try {
            // System resource analysis
            $resourceStatus = $this->analyzeResources($metrics['system']);
            
            // Performance metrics analysis  
            $performanceStatus = $this->analyzePerformance($metrics['performance']);
            
            // Security metrics analysis
            $securityStatus = $this->analyzeSecurity($metrics['security']);
            
            // Generate comprehensive analysis
            $analysis = $this->generateAnalysis([
                'resources' => $resourceStatus,
                'performance' => $performanceStatus,
                'security' => $securityStatus
            ]);
            
            // Check for critical thresholds
            $this->checkCriticalThresholds($analysis);
            
            DB::commit();
            
            return new AnalysisResult(
                status: $analysis['status'],
                metrics: $analysis['metrics'],
                alerts: $analysis['alerts']
            );
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new AnalysisException('Failed to analyze metrics: ' . $e->getMessage(), $e);
        }
    }

    private function analyzeResources(array $systemMetrics): array
    {
        $cpuStatus = $this->analyzeCPUUsage($systemMetrics['cpu']);
        $memoryStatus = $this->analyzeMemoryUsage($systemMetrics['memory']);
        $connectionStatus = $this->analyzeConnections($systemMetrics['connections']);
        
        return [
            'status' => $this->determineResourceStatus($cpuStatus, $memoryStatus, $connectionStatus),
            'cpu' => $cpuStatus,
            'memory' => $memoryStatus,
            'connections' => $connectionStatus
        ];
    }

    private function analyzePerformance(array $performanceMetrics): array
    {
        $responseStatus = $this->analyzeResponseTimes($performanceMetrics['response_time']);
        $throughputStatus = $this->analyzeThroughput($performanceMetrics['throughput']);
        $errorStatus = $this->analyzeErrorRates($performanceMetrics['error_rate']);
        
        return [
            'status' => $this->determinePerformanceStatus($responseStatus, $throughputStatus, $errorStatus),
            'response_times' => $responseStatus,
            'throughput' => $throughputStatus,
            'errors' => $errorStatus
        ];
    }

    private function analyzeSecurity(array $securityMetrics): array
    {
        return [
            'status' => $this->determineSecurityStatus($securityMetrics),
            'access' => $this->analyzeAccessPatterns($securityMetrics['access_attempts']),
            'validation' => $this->analyzeValidationMetrics($securityMetrics['validation_failures']),
            'threats' => $this->analyzeThreatMetrics($securityMetrics['threat_level'])
        ];
    }

    private function checkCriticalThresholds(array $analysis): void
    {
        // Check CPU usage
        if ($analysis['resources']['cpu']['usage'] > self::CRITICAL_CPU_THRESHOLD) {
            $this->alerts->sendCriticalAlert('CPU_THRESHOLD_EXCEEDED', [
                'current' => $analysis['resources']['cpu']['usage'],
                'threshold' => self::CRITICAL_CPU_THRESHOLD
            ]);
        }
        
        // Check memory usage
        if ($analysis['resources']['memory']['usage'] > self::CRITICAL_MEMORY_THRESHOLD) {
            $this->alerts->sendCriticalAlert('MEMORY_THRESHOLD_EXCEEDED', [
                'current' => $analysis['resources']['memory']['usage'],
                'threshold' => self::CRITICAL_MEMORY_THRESHOLD
            ]);
        }
        
        // Check error rate
        if ($analysis['performance']['errors']['rate'] > self::CRITICAL_ERROR_RATE) {
            $this->alerts->sendCriticalAlert('ERROR_RATE_THRESHOLD_EXCEEDED', [
                'current' => $analysis['performance']['errors']['rate'],
                'threshold' => self::CRITICAL_ERROR_RATE
            ]);
        }
    }

    private function generateAnalysis(array $metrics): array
    {
        return [
            'status' => $this->determineOverallStatus($metrics),
            'metrics' => $metrics,
            'alerts' => $this->generateAlerts($metrics),
            'recommendations' => $this->generateRecommendations($metrics)
        ];
    }

    private function determineOverallStatus(array $metrics): string
    {
        $criticalStatuses = [
            $metrics['resources']['status'],
            $metrics['performance']['status'],
            $metrics['security']['status']
        ];
        
        if (in_array('critical', $criticalStatuses)) {
            return 'critical';
        }
        
        if (in_array('warning', $criticalStatuses)) {
            return 'warning';
        }
        
        return 'normal';
    }
}
