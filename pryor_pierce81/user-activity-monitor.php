<?php

namespace App\Core\Monitoring\UserActivity;

class UserActivityMonitor
{
    private ActivityCollector $collector;
    private BehaviorAnalyzer $behaviorAnalyzer;
    private PatternDetector $patternDetector;
    private SecurityAnalyzer $securityAnalyzer;
    private AlertManager $alertManager;

    public function monitor(): ActivityStatus
    {
        $activities = $this->collector->collect();
        $behavior = $this->behaviorAnalyzer->analyze($activities);
        $patterns = $this->patternDetector->detect($activities);
        $security = $this->securityAnalyzer->analyze($activities);

        $status = new ActivityStatus($activities, $behavior, $patterns, $security);

        if ($status->hasIssues()) {
            $this->alertManager->notify(new ActivityAlert($status));
        }

        return $status;
    }
}

class ActivityCollector
{
    private array $collectors;
    private ActivityFilter $filter;
    private TimeWindow $window;

    public function collect(): ActivityCollection
    {
        $activities = [];
        foreach ($this->collectors as $collector) {
            try {
                $data = $collector->collect($this->window);
                $filtered = $this->filter->filter($data);
                $activities[$collector->getType()] = $filtered;
            } catch (\Exception $e) {
                $activities[$collector->getType()] = new CollectionError($e);
            }
        }

        return new ActivityCollection($activities);
    }
}

class BehaviorAnalyzer
{
    private UserProfiler $profiler;
    private AnomalyDetector $anomalyDetector;
    private RiskAssessor $riskAssessor;

    public function analyze(ActivityCollection $activities): BehaviorAnalysis
    {
        $profiles = $this->profiler->buildProfiles($activities);
        $anomalies = $this->anomalyDetector->detect($activities, $profiles);
        $risks = $this->riskAssessor->assess($activities, $anomalies);

        return new BehaviorAnalysis($profiles, $anomalies, $risks);
    }
}

class PatternDetector
{
    private array $detectors;
    private PatternMatcher $matcher;
    private TrendAnalyzer $trendAnalyzer;

    public function detect(ActivityCollection $activities): PatternAnalysis
    {
        $patterns = [];
        foreach ($this->detectors as $detector) {
            $detected = $detector->detect($activities);
            $patterns = array_merge($patterns, $detected);
        }

        $matches = $this->matcher->match($patterns);
        $trends = $this->trendAnalyzer->analyze($patterns);

        return new PatternAnalysis($patterns, $matches, $trends);
    }
}

class SecurityAnalyzer
{
    private AccessAnalyzer $accessAnalyzer;
    private ThreatDetector $threatDetector;
    private ComplianceChecker $complianceChecker;

    public function analyze(ActivityCollection $activities): SecurityAnalysis
    {
        $accessIssues = $this->accessAnalyzer->analyze($activities);
        $threats = $this->threatDetector->detect($activities);
        $compliance = $this->complianceChecker->check($activities);

        return new SecurityAnalysis($accessIssues, $threats, $compliance);
    }
}

class ActivityStatus
{
    private ActivityCollection $activities;
    private BehaviorAnalysis $behavior;
    private PatternAnalysis $patterns;
    private SecurityAnalysis $security;
    private float $timestamp;

    public function __construct(
        ActivityCollection $activities,
        BehaviorAnalysis $behavior,
        PatternAnalysis $patterns,
        SecurityAnalysis $security
    ) {
        $this->activities = $activities;
        $this->behavior = $behavior;
        $this->patterns = $patterns;
        $this->security = $security;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return $this->behavior->hasAnomalies() ||
               $this->patterns->hasSignificantPatterns() ||
               $this->security->hasIssues();
    }
}

class ActivityCollection
{
    private array $activities;
    private array $errors;
    private int $count;

    public function __construct(array $activities)
    {
        $this->activities = array_filter($activities, fn($a) => !($a instanceof CollectionError));
        $this->errors = array_filter($activities, fn($a) => $a instanceof CollectionError);
        $this->count = count($this->activities);
    }

    public function getActivities(): array
    {
        return $this->activities;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}

class BehaviorAnalysis
{
    private array $profiles;
    private array $anomalies;
    private array $risks;
    private float $timestamp;

    public function __construct(array $profiles, array $anomalies, array $risks)
    {
        $this->profiles = $profiles;
        $this->anomalies = $anomalies;
        $this->risks = $risks;
        $this->timestamp = microtime(true);
    }

    public function hasAnomalies(): bool
    {
        return !empty($this->anomalies);
    }

    public function getProfiles(): array
    {
        return $this->profiles;
    }

    public function getAnomalies(): array
    {
        return $this->anomalies;
    }

    public function getRisks(): array
    {
        return $this->risks;
    }
}

class SecurityAnalysis
{
    private array $accessIssues;
    private array $threats;
    private array $compliance;
    private float $timestamp;

    public function __construct(array $accessIssues, array $threats, array $compliance)
    {
        $this->accessIssues = $accessIssues;
        $this->threats = $threats;
        $this->compliance = $compliance;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return !empty($this->accessIssues) || !empty($this->threats) || !empty($this->compliance);
    }
}
