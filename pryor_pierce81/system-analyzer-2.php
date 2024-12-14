<?php

namespace App\Core\Security\Monitoring;

class SystemAnalyzer implements SystemAnalyzerInterface
{
    private ResourceMonitor $resourceMonitor;
    private SecurityMonitor $securityMonitor;
    private PerformanceAnalyzer $performanceAnalyzer;
    private StateValidator $stateValidator;
    private AnalysisLogger $logger;
    private MetricsCollector $metrics;

    public function __construct(
        ResourceMonitor $resourceMonitor,
        SecurityMonitor $securityMonitor,
        PerformanceAnalyzer $performanceAnalyzer,
        StateValidator $stateValidator,
        AnalysisLogger $logger,
        MetricsCollector $metrics
    ) {
        $this->resourceMonitor = $resourceMonitor;
        $this->securityMonitor = $securityMonitor;
        $this->performanceAnalyzer = $performanceAnalyzer;
        $this->stateValidator = $stateValidator;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function analyzeSystemState(): SystemState
    {
        try {
            // Collect current metrics
            $resourceMetrics = $this->resourceMonitor->collectMetrics();
            $securityMetrics = $this->securityMonitor->collectMetrics();
            $performanceMetrics = $this->performanceAnalyzer->collectMetrics();
            
            // Analyze system components
            $resourceAnalysis = $this->analyzeResources($resourceMetrics);
            $securityAnalysis = $this->analyzeSecurity($securityMetrics);
            $performanceAnalysis = $this->analyzePerformance($performanceMetrics);
            
            // Validate system state
            $this->validateSystemState([
                'resources' => $resourceAnalysis,
                'security' => $securityAnalysis,
                'performance' => $performanceAnalysis
            ]);
            
            // Create state snapshot
            $state = $this->createStateSnapshot([
                'resources' => $resourceMetrics,
                'security' => $securityMetrics,  
                'performance' => $performanceMetrics,
                'analysis' => [
                    'resources' => $resourceAnalysis,
                    'security' => $securityAnalysis,
                    'performance' => $performanceAnalysis
                ]
            ]);
            
            // Log state analysis
            $this->logStateAnalysis($state);
            
            return $state;
            
        } catch (AnalysisException $e) {
            $this->handleAnalysisFailure($e);
            throw $e;
        }
    }

    public function validateSystemIntegrity(): bool
    {
        try {
            // Get current state
            $state = $this->analyzeSystemState();
            
            // Validate state integrity
            $valid = $this->stateValidator->validateState($state);
            
            // Log validation result
            $this->logValidationResult($valid, $state);
            
            return $valid;
            
        } catch (ValidationException $e) {
            $this->handleValidationFailure($e);
            return false;
        }
    }

    private function analyzeResources(array $metrics): array
    {
        return [
            'cpu' => $this->analyzeCpuUsage($metrics['cpu']),
            'memory' => $this->analyzeMemoryUsage($metrics['memory']),
            'disk' => $this->analyzeDiskUsage($metrics['disk']),
            'network' => $this->analyzeNetworkUsage($metrics['network'])
        ];
    }

    private function analyzeSecurity(array $metrics): array
    {
        return [
            'threats' => $this->analyzeThreats($metrics['threats']),
            'vulnerabilities' => $this->analyzeVulnerabilities($metrics['vulnerabilities']),
            'anomalies' => $this->analyzeAnomalies($metrics['anomalies'])
        ];
    }

    private function analyzePerformance(array $metrics): array
    {
        return [
            'response_times' => $this->analyzeResponseTimes($metrics['response_times']),
            'throughput' => $this->analyzeThroughput($metrics['throughput']),
            'error_rates' => $this->analyzeErrorRates($metrics['error_rates'])
        ];
    }

    private function validateSystemState(array $analysis): void
    {
        if (!$this->stateValidator->isValid($analysis)) {
            throw new InvalidSystemStateException('System state validation failed');
        }
    }

    private function createStateSnapshot(array $data): SystemState
    {
        return new SystemState([
            'metrics' => $data['resources'],
            'security' => $data['security'],
            'performance' => $data['performance'],
            'analysis' => $data['analysis'],
            'timestamp' => now()
        ]);
    }

    private function handleAnalysisFailure(AnalysisException $e): void
    {
        $this->logger->logAnalysisFailure([
            'exception' => $e,
            'timestamp' => now(),
            'context' => [
                'resource_metrics' => $this->resourceMonitor->getLastMetrics(),
                'security_metrics' => $this->securityMonitor->getLastMetrics(),
                'performance_metrics' => $this->performanceAnalyzer->getLastMetrics()
            ]
        ]);
        
        $this->metrics->incrementFailureCount('system_analysis');
    }
}
