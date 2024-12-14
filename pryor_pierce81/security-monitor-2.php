<?php

namespace App\Core\Monitoring\Security;

class SecurityMonitor {
    private ThreatDetector $threatDetector;
    private AccessAnalyzer $accessAnalyzer;
    private ActivityTracker $activityTracker;
    private SecurityMetrics $metrics;
    private AlertDispatcher $alertDispatcher;

    public function __construct(
        ThreatDetector $threatDetector,
        AccessAnalyzer $accessAnalyzer,
        ActivityTracker $activityTracker,
        SecurityMetrics $metrics,
        AlertDispatcher $alertDispatcher
    ) {
        $this->threatDetector = $threatDetector;
        $this->accessAnalyzer = $accessAnalyzer;
        $this->activityTracker = $activityTracker;
        $this->metrics = $metrics;
        $this->alertDispatcher = $alertDispatcher;
    }

    public function monitor(SecurityContext $context): SecurityReport 
    {
        $threats = $this->threatDetector->detectThreats($context);
        $accessViolations = $this->accessAnalyzer->analyzeAccess($context);
        $suspiciousActivity = $this->activityTracker->trackActivity($context);

        $metrics = $this->metrics->collect($context);

        if ($threats || $accessViolations || $suspiciousActivity) {
            $this->alertDispatcher->dispatch(
                new SecurityAlert($threats, $accessViolations, $suspiciousActivity)
            );
        }

        return new SecurityReport(
            $threats,
            $accessViolations,
            $suspiciousActivity,
            $metrics
        );
    }
}

class ThreatDetector {
    private array $detectors;
    private RiskAssessor $riskAssessor;

    public function detectThreats(SecurityContext $context): array 
    {
        $threats = [];

        foreach ($this->detectors as $detector) {
            if ($detector->isApplicable($context)) {
                $detectedThreats = $detector->detect($context);
                if ($detectedThreats) {
                    foreach ($detectedThreats as $threat) {
                        $risk = $this->riskAssessor->assess($threat);
                        $threats[] = new Threat($threat, $risk);
                    }
                }
            }
        }

        return $threats;
    }
}

class AccessAnalyzer {
    private AccessLogReader $logReader;
    private PatternMatcher $patternMatcher;
    private array $rules;

    public function analyzeAccess(SecurityContext $context): array 
    {
        $violations = [];
        $logs = $this->logReader->getRecentLogs($context);

        foreach ($logs as $log) {
            foreach ($this->rules as $rule) {
                if ($rule->matches($log)) {
                    $violations[] = new AccessViolation($log, $rule);
                }
            }
        }

        return $violations;
    }
}

class ActivityTracker {
    private BehaviorAnalyzer $behaviorAnalyzer;
    private array $patterns;
    private array $thresholds;

    public function trackActivity(SecurityContext $context): array 
    {
        $activities = $this->behaviorAnalyzer->analyze($context);
        $suspicious = [];

        foreach ($activities as $activity) {
            if ($this->isSuspicious($activity)) {
                $suspicious[] = new SuspiciousActivity($activity);
            }
        }

        return $suspicious;
    }

    private function isSuspicious(Activity $activity): bool 
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern->matches($activity)) {
                $threshold = $this->thresholds[$pattern->getName()] ?? null;
                if ($threshold && $activity->getScore() > $threshold) {
                    return true;
                }
            }
        }
        return false;
    }
}

class SecurityMetrics {
    private array $collectors;
    private MetricsStorage $storage;

    public function collect(SecurityContext $context): array 
    {
        $metrics = [];

        foreach ($this->collectors as $collector) {
            $metrics = array_merge(
                $metrics,
                $collector->collect($context)
            );
        }

        $this->storage->store($metrics);
        
        return $metrics;
    }
}

class SecurityContext {
    private Request $request;
    private ?User $user;
    private array $environment;
    private float $timestamp;

    public function __construct(Request $request, ?User $user = null) 
    {
        $this->request = $request;
        $this->user = $user;
        $this->environment = $this->captureEnvironment();
        $this->timestamp = microtime(true);
    }

    private function captureEnvironment(): array 
    {
        return [
            'ip' => $this->request->getClientIp(),
            'user_agent' => $this->request->headers->get('User-Agent'),
            'referer' => $this->request->headers->get('Referer'),
            'method' => $this->request->getMethod(),
            'path' => $this->request->getPathInfo(),
            'query' => $this->request->query->all(),
            'timestamp' => time()
        ];
    }

    public function getRequest(): Request 
    {
        return $this->request;
    }

    public function getUser(): ?User 
    {
        return $this->user;
    }

    public function getEnvironment(): array 
    {
        return $this->environment;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }
}

class SecurityReport {
    private array $threats;
    private array $accessViolations;
    private array $suspiciousActivities;
    private array $metrics;
    private float $timestamp;

    public function __construct(
        array $threats,
        array $accessViolations,
        array $suspiciousActivities,
        array $metrics
    ) {
        $this->threats = $threats;
        $this->accessViolations = $accessViolations;
        $this->suspiciousActivities = $suspiciousActivities;
        $this->metrics = $metrics;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool 
    {
        return !empty($this->threats) || 
               !empty($this->accessViolations) || 
               !empty($this->suspiciousActivities);
    }

    public function getSeverity(): string 
    {
        if (!empty($this->threats)) {
            return 'critical';
        }
        if (!empty($this->accessViolations)) {
            return 'warning';
        }
        if (!empty($this->suspiciousActivities)) {
            return 'notice';
        }
        return 'info';
    }

    public function toArray(): array 
    {
        return [
            'severity' => $this->getSeverity(),
            'timestamp' => $this->timestamp,
            'threats' => array_map(fn($t) => $t->toArray(), $this->threats),
            'access_violations' => array_map(fn($v) => $v->toArray(), $this->accessViolations),
            'suspicious_activities' => array_map(fn($a) => $a->toArray(), $this->suspiciousActivities),
            'metrics' => $this->metrics
        ];
    }
}

class SecurityAlert {
    private array $threats;
    private array $accessViolations;
    private array $suspiciousActivities;
    private float $timestamp;

    public function __construct(
        array $threats,
        array $accessViolations,
        array $suspiciousActivities
    ) {
        $this->threats = $threats;
        $this->accessViolations = $accessViolations;
        $this->suspiciousActivities = $suspiciousActivities;
        $this->timestamp = microtime(true);
    }

    public function getSeverity(): string 
    {
        if (!empty($this->threats)) {
            return 'critical';
        }
        if (!empty($this->accessViolations)) {
            return 'warning';
        }
        return 'notice';
    }

    public function toArray(): array 
    {
        return [
            'severity' => $this->getSeverity(),
            'timestamp' => $this->timestamp,
            'details' => [
                'threats' => array_map(fn($t) => $t->toArray(), $this->threats),
                'access_violations' => array_map(fn($v) => $v->toArray(), $this->accessViolations),
                'suspicious_activities' => array_map(fn($a) => $a->toArray(), $this->suspiciousActivities)
            ]
        ];
    }
}

class Threat {
    private string $type;
    private float $riskScore;
    private array $details;
    private float $timestamp;

    public function __construct(string $type, float $riskScore, array $details = []) 
    {
        $this->type = $type;
        $this->riskScore = $riskScore;
        $this->details = $details;
        $this->timestamp = microtime(true);
    }

    public function getType(): string 
    {
        return $this->type;
    }

    public function getRiskScore(): float 
    {
        return $this->riskScore;
    }

    public function getDetails(): array 
    {
        return $this->details;
    }

    public function toArray(): array 
    {
        return [
            'type' => $this->type,
            'risk_score' => $this->riskScore,
            'details' => $this->details,
            'timestamp' => $this->timestamp
        ];
    }
}

