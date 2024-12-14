<?php

namespace App\Core\Alert;

class AlertService implements AlertInterface 
{
    private ThresholdManager $thresholdManager;
    private PatternDetector $patternDetector;
    private AlertProcessor $processor;
    private NotificationDispatcher $dispatcher;
    private EscalationManager $escalationManager;
    private AlertLogger $logger;

    public function __construct(
        ThresholdManager $thresholdManager,
        PatternDetector $patternDetector,
        AlertProcessor $processor,
        NotificationDispatcher $dispatcher,
        EscalationManager $escalationManager,
        AlertLogger $logger
    ) {
        $this->thresholdManager = $thresholdManager;
        $this->patternDetector = $patternDetector;
        $this->processor = $processor;
        $this->dispatcher = $dispatcher;
        $this->escalationManager = $escalationManager;
        $this->logger = $logger;
    }

    public function processAlert(AlertContext $context): AlertResult 
    {
        $alertId = $this->initializeAlert($context);
        
        try {
            DB::beginTransaction();
            
            $this->validateContext($context);
            $patterns = $this->detectPatterns($context);
            $severity = $this->calculateSeverity($patterns);
            
            $alert = new Alert([
                'id' => $alertId,
                'context' => $context,
                'patterns' => $patterns,
                'severity' => $severity,
                'timestamp' => now()
            ]);
            
            $processedAlert = $this->processor->process($alert);
            $this->distributeAlert($processedAlert);
            
            if ($this->requiresEscalation($processedAlert)) {
                $this->escalate($processedAlert);
            }
            
            DB::commit();
            
            return new AlertResult([
                'alert' => $processedAlert,
                'status' => AlertStatus::PROCESSED
            ]);

        } catch (AlertException $e) {
            DB::rollBack();
            $this->handleAlertFailure($e, $alertId);
            throw new CriticalAlertException($e->getMessage(), $e);
        }
    }

    private function detectPatterns(AlertContext $context): array 
    {
        return $this->patternDetector->detect($context);
    }

    private function calculateSeverity(array $patterns): AlertSeverity 
    {
        $maxSeverity = AlertSeverity::LOW;
        
        foreach ($patterns as $pattern) {
            if ($pattern->severity->value > $maxSeverity->value) {
                $maxSeverity = $pattern->severity;
            }
        }
        
        return $maxSeverity;
    }

    private function distributeAlert(Alert $alert): void 
    {
        $this->dispatcher->dispatch($alert, $this->determineTargets($alert));
    }

    private function requiresEscalation(Alert $alert): bool 
    {
        return $alert->severity >= AlertSeverity::HIGH ||
               $this->thresholdManager->isThresholdExceeded($alert);
    }

    private function escalate(Alert $alert): void 
    {
        $this->escalationManager->escalate(
            new EscalationRequest([
                'alert' => $alert,
                'priority' => EscalationPriority::IMMEDIATE
            ])
        );
    }

    private function handleAlertFailure(AlertException $e, string $alertId): void 
    {
        $this->logger->logFailure($e, $alertId);
        
        if ($e->isCritical()) {
            $this->escalationManager->escalate(
                new EscalationRequest([
                    'exception' => $e,
                    'alertId' => $alertId,
                    'priority' => EscalationPriority::CRITICAL
                ])
            );
        }
    }
}
