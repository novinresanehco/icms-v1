<?php

namespace App\Core\Security\Audit;

class AuditLogger implements AuditLoggerInterface
{
    private LogStore $store;
    private LogProcessor $processor;
    private AlertManager $alertManager;
    private MetricsCollector $metrics;
    private array $config;

    public function logCriticalEvent(array $data): void
    {
        $operationId = uniqid('audit_', true);

        try {
            // Process critical event data
            $processedData = $this->processor->processCriticalEvent($data);

            // Validate event data
            $this->validateCriticalEvent($processedData);

            // Store event with highest priority
            $this->store->storeCritical($processedData, [
                'priority' => 'CRITICAL',
                'retention' => $this->config['critical_retention_period']
            ]);

            // Trigger immediate alerts
            $this->alertManager->triggerCriticalAlert($processedData);

            // Update security metrics
            $this->updateSecurityMetrics($processedData);

            // Execute critical event protocols
            $this->executeCriticalProtocols($processedData);

        } catch (\Throwable $e) {
            $this->handleLoggingFailure($e, $data, $operationId);
            throw $e;
        }
    }

    public function logSecurityEvent(array $data): void
    {
        $operationId = uniqid('security_', true);

        try {
            // Process security event
            $processedData = $this->processor->processSecurityEvent($data);

            // Store with security context
            $this->store->storeSecurity($processedData, [
                'context' => 'security',
                'retention' => $this->config['security_retention_period']
            ]);

            // Check for security patterns
            $this->analyzeSecurityPatterns($processedData);

            // Update metrics
            $this->metrics->recordSecurityEvent($processedData);

        } catch (\Throwable $e) {
            $this->handleLoggingFailure($e, $data, $operationId);
            throw $e;
        }
    }

    protected function validateCriticalEvent(array $data): void
    {
        // Validate required fields
        if (!isset($data['type'], $data['severity'], $data['timestamp'])) {
            throw new ValidationException('Missing required critical event fields');
        }

        // Validate severity level
        if ($data['severity'] !== 'CRITICAL') {
            throw new ValidationException('Invalid severity for critical event');
        }

        // Validate event type
        if (!in_array($data['type'], $this->config['critical_event_types'])) {
            throw new ValidationException('Invalid critical event type');
        }

        // Validate data integrity
        if (!$this->processor->validateEventIntegrity($data)) {
            throw new ValidationException('Critical event data integrity check failed');
        }
    }

    protected function updateSecurityMetrics(array $data): void
    {
        $this->metrics->increment('security.critical_events_total');
        $this->metrics->gauge('security.last_critical_event', time());
        
        if (isset($data['category'])) {
            $this->metrics->increment(
                "security.critical_events.{$data['category']}"
            );
        }
    }

    protected function executeCriticalProtocols(array $data): void
    {
        // Execute response protocols
        foreach ($this->config['critical_protocols'] as $protocol) {
            try {
                $protocol->execute($data);
            } catch (\Throwable $e) {
                $this->handleProtocolFailure($e, $protocol, $data);
            }
        }

        // Update system state
        $this->updateSystemState($data);

        // Notify security team
        $this->notifySecurityTeam($data);
    }

    protected function analyzeSecurityPatterns(array $data): void
    {
        $patterns = $this->processor->detectSecurityPatterns($data);

        if (!empty($patterns)) {
            foreach ($patterns as $pattern) {
                if ($this->isHighRiskPattern($pattern)) {
                    $this->handleHighRiskPattern($pattern, $data);
                }
            }
        }
    }

    protected function isHighRiskPattern(array $pattern): bool
    {
        return $pattern['risk_score'] >= $this->config['high_risk_threshold'];
    }

    protected function handleHighRiskPattern(array $pattern, array $data): void
    {
        // Log high risk pattern
        $this->store->storeHighRisk([
            'pattern' => $pattern,
            'event_data' => $data,
            'timestamp' => time(),
            'risk_score' => $pattern['risk_score']
        ]);

        // Trigger alerts
        $this->alertManager->triggerRiskAlert($pattern);

        // Update risk metrics
        $this->metrics->increment('security.high_risk_patterns');
    }

    protected function handleLoggingFailure(
        \Throwable $e,
        array $data,
        string $operationId
    ): void {
        try {
            // Store failure event
            $this->store->storeFailure([
                'error' => $e->getMessage(),
                'data' => $data,
                'operation_id' => $operationId,
                'timestamp' => time()
            ]);

            if ($this->isCriticalFailure($e)) {
                $this->handleCriticalFailure($e, $data, $operationId);
            }

        } catch (\Throwable $fallbackError) {
            // Last resort error handling
            error_log("Critical logging failure: {$fallbackError->getMessage()}");
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof CriticalLoggingException ||
               $e instanceof SecurityLoggingException;
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        array $data,
        string $operationId
    ): void {
        // Use fallback logger
        $this->getFallbackLogger()->logCritical([
            'message' => 'Critical audit logging failure',
            'error' => $e->getMessage(),
            'data' => $data,
            'operation_id' => $operationId,
            'timestamp' => time()
        ]);

        // Notify emergency contacts
        $this->notifyEmergencyContacts($e, $data);

        // Execute emergency protocols
        $this->executeEmergencyProtocols();
    }

    protected function notifyEmergencyContacts(\Throwable $e, array $data): void
    {
        foreach ($this->config['emergency_contacts'] as $contact) {
            try {
                $this->alertManager->notifyEmergencyContact($contact, [
                    'type' => 'critical_logging_failure',
                    'error' => $e->getMessage(),
                    'data' => $data,
                    'severity' => 'CRITICAL'
                ]);
            } catch (\Throwable $notifyError) {
                // Log notification failure but continue with others
                error_log("Failed to notify emergency contact: {$notifyError->getMessage()}");
            }
        }
    }
}
