<?php

namespace App\Core\Security;

/**
 * Critical Security Audit System
 * Handles comprehensive security event logging and real-time monitoring
 */
class SecurityAuditSystem implements AuditSystemInterface 
{
    private EventLogger $logger;
    private RealTimeMonitor $monitor;
    private SecurityAnalyzer $analyzer;
    private AlertDispatcher $alertDispatcher;
    private MetricsCollector $metrics;

    public function __construct(
        EventLogger $logger,
        RealTimeMonitor $monitor,
        SecurityAnalyzer $analyzer,
        AlertDispatcher $alertDispatcher,
        MetricsCollector $metrics
    ) {
        $this->logger = $logger;
        $this->monitor = $monitor;
        $this->analyzer = $analyzer;
        $this->alertDispatcher = $alertDispatcher;
        $this->metrics = $metrics;
    }

    /**
     * Records a critical security event with comprehensive context
     *
     * @param SecurityEvent $event The security event to record
     * @param SecurityContext $context Security context including user and environment data
     * @throws AuditFailureException If the event cannot be properly recorded
     */
    public function recordSecurityEvent(SecurityEvent $event, SecurityContext $context): void
    {
        DB::beginTransaction();

        try {
            // Generate unique audit ID for cross-referencing
            $auditId = $this->generateAuditId($event);
            
            // Capture comprehensive event context
            $eventContext = $this->captureEventContext($event, $context);
            
            // Analyze event severity and impact
            $analysis = $this->analyzer->analyzeEvent($event, $eventContext);
            
            // Record event with full context
            $this->logger->logSecurityEvent([
                'audit_id' => $auditId,
                'event' => $event->toArray(),
                'context' => $eventContext,
                'analysis' => $analysis,
                'timestamp' => now(),
                'system_state' => $this->captureSystemState()
            ]);

            // Update security metrics
            $this->metrics->recordSecurityMetrics($event, $analysis);

            // Trigger real-time monitoring alerts if needed
            if ($analysis->requiresAlert()) {
                $this->dispatchSecurityAlert($event, $analysis);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($event, $e);
            throw new AuditFailureException(
                'Failed to record security event: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Performs real-time analysis of system security status
     *
     * @return SecurityStatus Current security status with threat levels and metrics
     */
    public function analyzeSecurityStatus(): SecurityStatus
    {
        $monitoringData = $this->monitor->getCurrentStatus();
        
        return new SecurityStatus(
            threatLevel: $this->analyzer->assessThreatLevel($monitoringData),
            activeThreats: $this->analyzer->detectActiveThreats(),
            securityMetrics: $this->metrics->getCurrentMetrics(),
            systemHealth: $this->monitor->getSystemHealth(),
            timestamp: now()
        );
    }

    /**
     * Generates comprehensive security report for specified time period
     *
     * @param DateTimeInterface $startDate Start of reporting period
     * @param DateTimeInterface $endDate End of reporting period
     * @return SecurityReport Detailed security analysis and metrics
     */
    public function generateSecurityReport(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate
    ): SecurityReport {
        $events = $this->logger->getSecurityEvents($startDate, $endDate);
        $metrics = $this->metrics->getMetricsForPeriod($startDate, $endDate);
        $analysis = $this->analyzer->analyzeTimeframe($startDate, $endDate);

        return new SecurityReport(
            timeframe: ['start' => $startDate, 'end' => $endDate],
            events: $events,
            metrics: $metrics,
            analysis: $analysis,
            recommendations: $this->analyzer->generateRecommendations($analysis),
            generatedAt: now()
        );
    }

    private function captureEventContext(SecurityEvent $event, SecurityContext $context): array
    {
        return [
            'user' => [
                'id' => $context->getUserId(),
                'role' => $context->getUserRole(),
                'permissions' => $context->getUserPermissions(),
                'session' => $context->getSessionData()
            ],
            'environment' => [
                'ip' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'timestamp' => $context->getTimestamp(),
                'geo_location' => $context->getGeoLocation()
            ],
            'request' => [
                'method' => $context->getRequestMethod(),
                'uri' => $context->getRequestUri(),
                'headers' => $context->getRequestHeaders(),
                'parameters' => $context->getRequestParameters()
            ],
            'security' => [
                'access_level' => $context->getAccessLevel(),
                'encryption_status' => $context->getEncryptionStatus(),
                'security_flags' => $context->getSecurityFlags(),
                'compliance_status' => $context->getComplianceStatus()
            ]
        ];
    }

    private function captureSystemState(): array
    {
        return [
            'performance' => [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'cpu_usage' => sys_getloadavg()
            ],
            'security' => [
                'threat_level' => $this->analyzer->getCurrentThreatLevel(),
                'active_threats' => $this->analyzer->getActiveThreatsCount(),
                'security_score' => $this->analyzer->getSecurityScore()
            ],
            'resources' => [
                'connections' => $this->monitor->getActiveConnections(),
                'processes' => $this->monitor->getActiveProcesses(),
                'load' => $this->monitor->getSystemLoad()
            ]
        ];
    }

    private function dispatchSecurityAlert(SecurityEvent $event, EventAnalysis $analysis): void
    {
        $this->alertDispatcher->dispatch(
            new SecurityAlert(
                event: $event,
                analysis: $analysis,
                priority: $analysis->getSeverityLevel(),
                timestamp: now(),
                recipients: $this->determineAlertRecipients($analysis)
            )
        );
    }

    private function generateAuditId(SecurityEvent $event): string
    {
        return hash('sha256', json_encode([
            'event_type' => $event->getType(),
            'timestamp' => $event->getTimestamp(),
            'random' => random_bytes(16)
        ]));
    }

    private function handleAuditFailure(SecurityEvent $event, \Exception $e): void
    {
        // Log to emergency backup system
        $this->logger->emergency('Audit system failure', [
            'event' => $event->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);

        // Notify security team
        $this->alertDispatcher->dispatchCritical(
            new AuditFailureAlert(
                event: $event,
                error: $e,
                timestamp: now()
            )
        );
    }

    private function determineAlertRecipients(EventAnalysis $analysis): array
    {
        return match ($analysis->getSeverityLevel()) {
            SeverityLevel::CRITICAL => $this->getEmergencyContacts(),
            SeverityLevel::HIGH => $this->getSecurityTeam(),
            SeverityLevel::MEDIUM => $this->getSystemAdministrators(),
            default => $this->getStandardRecipients()
        };
    }
}
