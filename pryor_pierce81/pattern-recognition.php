<?php

namespace App\Core\Recognition;

class PatternRecognitionService implements PatternInterface
{
    private AIEngine $aiEngine;
    private PatternRepository $patternRepository;
    private StateAnalyzer $stateAnalyzer;
    private ValidationEngine $validator;
    private RecognitionLogger $logger;
    private AlertDispatcher $alertDispatcher;

    public function __construct(
        AIEngine $aiEngine,
        PatternRepository $patternRepository,
        StateAnalyzer $stateAnalyzer,
        ValidationEngine $validator,
        RecognitionLogger $logger,
        AlertDispatcher $alertDispatcher
    ) {
        $this->aiEngine = $aiEngine;
        $this->patternRepository = $patternRepository;
        $this->stateAnalyzer = $stateAnalyzer;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->alertDispatcher = $alertDispatcher;
    }

    public function recognizePatterns(SystemState $state): PatternResult
    {
        $recognitionId = $this->initializeRecognition($state);
        
        try {
            DB::beginTransaction();
            
            $knownPatterns = $this->patternRepository->getPatterns();
            $stateAnalysis = $this->stateAnalyzer->analyze($state);
            
            $detectedPatterns = $this->aiEngine->detectPatterns(
                $stateAnalysis,
                $knownPatterns
            );
            
            $this->validatePatterns($detectedPatterns);
            
            $result = new PatternResult([
                'recognitionId' => $recognitionId,
                'patterns' => $detectedPatterns,
                'analysis' => $stateAnalysis,
                'timestamp' => now()
            ]);
            
            DB::commit();
            $this->finalizeRecognition($result);
            
            return $result;

        } catch (PatternException $e) {
            DB::rollBack();
            $this->handleRecognitionFailure($e, $recognitionId);
            throw new CriticalPatternException($e->getMessage(), $e);
        }
    }

    private function validatePatterns(array $patterns): void
    {
        foreach ($patterns as $pattern) {
            if (!$this->validator->validatePattern($pattern)) {
                throw new PatternValidationException(
                    "Invalid pattern detected: {$pattern->getId()}"
                );
            }

            if ($pattern->criticality >= PatternCriticality::HIGH) {
                $this->alertDispatcher->dispatch(
                    new CriticalPatternAlert($pattern)
                );
            }
        }
    }

    private function finalizeRecognition(PatternResult $result): void
    {
        $this->logger->logRecognition($result);
        $this->patternRepository->storeResults($result);
        
        if ($this->hasAnomalies($result)) {
            $this->alertDispatcher->dispatch(
                new AnomalyAlert($result)
            );
        }
    }
}
