<?php

namespace App\Core\Security\Analysis;

class AiEngine implements AiEngineInterface
{
    private ModelManager $modelManager;
    private DataProcessor $dataProcessor;
    private InferenceEngine $inference;
    private AuditLogger $logger;
    private array $config;

    public function analyzePatterns(string $code, array $options = []): AiAnalysisResult
    {
        $operationId = uniqid('ai_analysis_', true);

        try {
            // Prepare analysis
            $this->validateModel($options['model']);
            $this->configureEngine($options);

            // Process code for analysis
            $processedData = $this->processInput($code);
            
            // Run AI analysis
            $predictions = $this->runInference($processedData);
            
            // Post-process results
            $result = $this->postProcessResults(
                $predictions,
                $code,
                $operationId
            );

            // Validate results
            $this->validateResults($result);

            // Log completion
            $this->logAnalysisCompletion($result, $operationId);

            return $result;

        } catch (\Throwable $e) {
            $this->handleAnalysisFailure($e, $operationId);
            throw $e;
        }
    }

    protected function validateModel(string $modelId): void
    {
        if (!$this->modelManager->hasModel($modelId)) {
            throw new AiException('Model not found');
        }

        if (!$this->modelManager->validateModel($modelId)) {
            throw new AiException('Model validation failed');
        }

        if (!$this->modelManager->checkVersion($modelId)) {
            throw new AiException('Model version mismatch');
        }
    }

    protected function configureEngine(array $options): void
    {
        $this->inference->configure([
            'batch_size' => $options['batch_size'] ?? $this->config['default_batch_size'],
            'threshold' => $options['confidence_threshold'] ?? $this->config['default_threshold'],
            'device' => $options['device'] ?? $this->config['default_device'],
            'precision' => $options['precision'] ?? $this->config['default_precision']
        ]);
    }

    protected function processInput(string $code): array
    {
        // Tokenize code
        $tokens = $this->dataProcessor->tokenize($code);

        // Extract features
        $features = $this->dataProcessor->extractFeatures($tokens);

        // Normalize data
        return $this->dataProcessor->normalize($features);
    }

    protected function runInference(array $processedData): array
    {
        $predictions = $this->inference->predict($processedData);

        if (!$this->validatePredictions($predictions)) {
            throw new AiException('Invalid prediction output');
        }

        return $predictions;
    }

    protected function postProcessResults(
        array $predictions,
        string $code,
        string $operationId
    ): AiAnalysisResult {
        // Filter predictions
        $filteredPredictions = $this->filterPredictions($predictions);

        // Map predictions to code locations
        $mappedResults = $this->mapToCodeLocations($filteredPredictions, $code);

        // Calculate confidence scores
        $confidenceScores = $this->calculateConfidenceScores($mappedResults);

        // Generate insights
        $insights = $this->generateInsights($mappedResults, $code);

        return new AiAnalysisResult(
            $mappedResults,
            $confidenceScores,
            $insights,
            $operationId
        );
    }

    protected function validatePredictions(array $predictions): bool
    {
        foreach ($predictions as $prediction) {
            if (!$this->isValidPrediction($prediction)) {
                return false;
            }
        }
        return true;
    }

    protected function isValidPrediction(array $prediction): bool
    {
        return isset($prediction['pattern']) &&
               isset($prediction['confidence']) &&
               isset($prediction['location']);
    }

    protected function filterPredictions(array $predictions): array
    {
        return array_filter($predictions, function($prediction) {
            return $prediction['confidence'] >= $this->config['confidence_threshold'];
        });
    }

    protected function mapToCodeLocations(array $predictions, string $code): array
    {
        $mappedResults = [];

        foreach ($predictions as $prediction) {
            $location = $this->dataProcessor->mapToSource(
                $prediction['location'],
                $code
            );

            if ($location) {
                $mappedResults[] = array_merge($prediction, [
                    'source_location' => $location
                ]);
            }
        }

        return $mappedResults;
    }

    protected function calculateConfidenceScores(array $results): array
    {
        $scores = [];

        foreach ($results as $result) {
            $pattern = $result['pattern'];
            $confidence = $result['confidence'];

            if (!isset($scores[$pattern])) {
                $scores[$pattern] = [
                    'min' => $confidence,
                    'max' => $confidence,
                    'sum' => $confidence,
                    'count' => 1
                ];
            } else {
                $scores[$pattern]['min'] = min($scores[$pattern]['min'], $confidence);
                $scores[$pattern]['max'] = max($scores[$pattern]['max'], $confidence);
                $scores[$pattern]['sum'] += $confidence;
                $scores[$pattern]['count']++;
            }
        }

        // Calculate averages
        foreach ($scores as &$score) {
            $score['avg'] = $score['sum'] / $score['count'];
        }

        return $scores;
    }

    protected function generateInsights(array $results, string $code): array
    {
        $insights = [];

        // Group results by pattern
        $groupedResults = $this->groupResultsByPattern($results);

        foreach ($groupedResults as $pattern => $patternResults) {
            $insights[$pattern] = [
                'frequency' => count($patternResults),
                'severity' => $this->determineSeverity($patternResults),
                'recommendations' => $this->generateRecommendations($pattern, $patternResults),
                'context' => $this->extractContext($patternResults, $code)
            ];
        }

        return $insights;
    }

    protected function determineSeverity(array $patternResults): string
    {
        $maxConfidence = max(array_column($patternResults, 'confidence'));

        if ($maxConfidence >= $this->config['critical_confidence_threshold']) {
            return 'CRITICAL';
        }

        if ($maxConfidence >= $this->config['high_confidence_threshold']) {
            return 'HIGH';
        }

        return 'MEDIUM';
    }

    protected function handleAnalysisFailure(
        \Throwable $e,
        string $operationId
    ): void {
        $this->logger->error('AI analysis failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $operationId);
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof CriticalAiException ||
               $e instanceof ModelFailureException;
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        string $operationId
    ): void {
        $this->logger->critical('Critical AI analysis failure', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifySecurityTeam([
            'type' => 'critical_ai_failure',
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);
    }
}
