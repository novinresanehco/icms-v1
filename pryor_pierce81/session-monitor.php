<?php

namespace App\Core\Monitoring\Session;

class SessionMonitor
{
    private SessionManager $sessionManager;
    private MetricsCollector $metricsCollector;
    private HealthChecker $healthChecker;
    private StorageAnalyzer $storageAnalyzer;
    private SecurityValidator $securityValidator;
    private AlertDispatcher $alertDispatcher;

    public function monitor(): SessionReport
    {
        $metrics = $this->metricsCollector->collect();
        $health = $this->healthChecker->check();
        $storage = $this->storageAnalyzer->analyze();
        $security = $this->securityValidator->validate();

        $report = new SessionReport($metrics, $health, $storage, $security);

        if ($report->hasIssues()) {
            $this->alertDispatcher->dispatch(new SessionAlert($report));
        }

        return $report;
    }

    public function monitorSession(string $sessionId): SessionStatus
    {
        $session = $this->sessionManager->getSession($sessionId);
        if (!$session) {
            throw new SessionNotFoundException($sessionId);
        }

        return new SessionStatus(
            $session,
            $this->metricsCollector->collectForSession($session),
            $this->healthChecker->checkSession($session),
            $this->securityValidator->validateSession($session)
        );
    }
}

class MetricsCollector
{
    private SessionCounter $counter;
    private UsageAnalyzer $usageAnalyzer;
    private ResourceTracker $resourceTracker;

    public function collect(): SessionMetrics
    {
        return new SessionMetrics([
            'active_sessions' => $this->counter->countActiveSessions(),
            'usage_stats' => $this->usageAnalyzer->getStats(),
            'resource_usage' => $this->resourceTracker->getUsage()
        ]);
    }

    public function collectForSession(Session $session): SessionMetrics
    {
        return new SessionMetrics([
            'duration' => $this->calculateDuration($session),
            'activity' => $this->usageAnalyzer->getSessionActivity($session),
            'resources' => $this->resourceTracker->getSessionResources($session)
        ]);
    }

    private function calculateDuration(Session $session): float
    {
        return microtime(true) - $session->getStartTime();
    }
}

class HealthChecker
{
    private StorageHealthChecker $storageChecker;
    private HandlerHealthChecker $handlerChecker;
    private ConfigValidator $configValidator;

    public function check(): HealthStatus
    {
        $issues = [];

        try {
            if (!$this->storageChecker->isHealthy()) {
                $issues[] = new HealthIssue('storage', 'Session storage is unhealthy');
            }

            if (!$this->handlerChecker->isHealthy()) {
                $issues[] = new HealthIssue('handler', 'Session handler is unhealthy');
            }

            $configIssues = $this->configValidator->validate();
            if (!empty($configIssues)) {
                $issues = array_merge($issues, $configIssues);
            }
        } catch (\Exception $e) {
            $issues[] = new HealthIssue('check_failure', $e->getMessage());
        }

        return new HealthStatus($issues);
    }

    public function checkSession(Session $session): HealthStatus
    {
        $issues = [];

        try {
            if (!$session->isValid()) {
                $issues[] = new HealthIssue('validity', 'Session is invalid');
            }

            if ($session->isExpired()) {
                $issues[] = new HealthIssue('expiration', 'Session has expired');
            }
        } catch (\Exception $e) {
            $issues[] = new HealthIssue('check_failure', $e->getMessage());
        }

        return new HealthStatus($issues);
    }
}

class StorageAnalyzer
{
    private StorageMetrics $metrics;
    private CapacityAnalyzer $capacityAnalyzer;
    private PerformanceAnalyzer $performanceAnalyzer;

    public function analyze(): StorageAnalysis
    {
        $metrics = $this->metrics->collect();
        $capacity = $this->capacityAnalyzer->analyze($metrics);
        $performance = $this->performanceAnalyzer->analyze($metrics);

        return new StorageAnalysis($metrics, $capacity, $performance);
    }
}

class SecurityValidator
{
    private ConfigurationValidator $configValidator;
    private SessionValidator $sessionValidator;
    private ThreatDetector $threatDetector;

    public function validate(): SecurityStatus
    {
        $issues = [];

        $configIssues = $this->configValidator->validate();
        $sessionIssues = $this->sessionValidator->validate();
        $threats = $this->threatDetector->detect();

        return new SecurityStatus(
            array_merge($configIssues, $sessionIssues),
            $threats
        );
    }

    public function validateSession(Session $session): SecurityStatus
    {
        $issues = [];

        if (!$session->isSecure()) {
            $issues[] = new SecurityIssue('security', 'Session is not secure');
        }

        $validationIssues = $this->sessionValidator->validateSession($session);
        $threats = $this->threatDetector->detectForSession($session);

        return new SecurityStatus(
            array_merge($issues, $validationIssues),
            $threats
        );
    }
}

class SessionReport
{
    private SessionMetrics $metrics;
    private HealthStatus $health;
    private StorageAnalysis $storage;
    private SecurityStatus $security;
    private float $timestamp;

    public function __construct(
        SessionMetrics $metrics,
        HealthStatus $health,
        StorageAnalysis $storage,
        SecurityStatus $security
    ) {
        $this->metrics = $metrics;
        $this->health = $health;
        $this->storage = $storage;
        $this->security = $security;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return $this->health->hasIssues() ||
               $this->storage->hasIssues() ||
               $this->security->hasIssues();
    }

    public function getMetrics(): SessionMetrics
    {
        return $this->metrics;
    }

    public function getHealth(): HealthStatus
    {
        return $this->health;
    }

    public function getStorage(): StorageAnalysis
    {
        return $this->storage;
    }

    public function getSecurity(): SecurityStatus
    {
        return $this->security;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class SessionStatus
{
    private Session $session;
    private SessionMetrics $metrics;
    private HealthStatus $health;
    private SecurityStatus $security;

    public function __construct(
        Session $session,
        SessionMetrics $metrics,
        HealthStatus $health,
        SecurityStatus $security
    ) {
        $this->session = $session;
        $this->metrics = $metrics;
        $this->health = $health;
        $this->security = $security;
    }

    public function hasIssues(): bool
    {
        return $this->health->hasIssues() || $this->security->hasIssues();
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getMetrics(): SessionMetrics
    {
        return $this->metrics;
    }

    public function getHealth(): HealthStatus
    {
        return $this->health;
    }

    public function getSecurity(): SecurityStatus
    {
        return $this->security;
    }
}
