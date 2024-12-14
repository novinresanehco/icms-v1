<?php

namespace App\Core\AI;

class AIValidationService implements AIValidationInterface
{
    private PatternMatcher $patternMatcher;
    private ModelExecutor $modelExecutor;
    private DecisionEngine $decisionEngine;
    private ValidationMetrics $metrics;
    private AILogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        PatternMatcher $patternMatcher,
        ModelExecutor $modelExecutor,
        DecisionEngine $decisionEngine,
        ValidationMetrics $metrics,
        AILogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->patternMatcher = $patternMatcher;
        $this->modelExecutor = $modelExecutor;
        $this->decisionEngine = $decisionEngine;
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function validatePattern(PatternContext $context): ValidationResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $pattern = $this->patternMatcher->matchPattern($context);
            $this->validatePatternIntegrity($pattern);

            $modelResult = $this->modelExecutor->executeModel($pattern);
            $this->validateModelExecution($modelResult);

            $decision = $this->decisionEngine->analyzeResult($modelResult);
            $this->validateDecision($decision);

            $result = new ValidationResult([
                'validationId' => $validationId,
                'pattern' => $pattern,
                'modelResult' => $modelResult,
                'decision' => $decision,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (AIValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalAIException($e->getMessage(), $e);
        }
    }

    private function validatePatternIntegrity(Pattern $pattern): void
    {
        if (!$pattern->isValid()) {
            $this->emergency->handleInvalidPattern($pattern);
            throw new InvalidPatternException('Pattern integrity validation failed');
        }
    }

    private function validateModelExecution(ModelResult $result): void
    {
        if ($result->hasAnomalies()) {
            $this->emergency->handleModelAnomalies($result->getAnomalies());
            throw new ModelExecutionException('Model execution anomalies detected');
        }
    }

    private function validateDecision(Decision $decision): void
    {
        if ($decision->confidenceLevel < config('ai.minimum_confidence')) {
            $this->emergency->handleLowConfidence($decision);
            throw new LowConfidenceException('Decision confidence below threshold');
        }
    }
}
