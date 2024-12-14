<?php

namespace App\Core\Audit\Checkers;

class HealthChecker
{
    private array $checks;
    private LoggerInterface $logger;
    private array $results = [];

    public function __construct(array $checks, LoggerInterface $logger)
    {
        $this->checks = $checks;
        $this->logger = $logger;
    }

    public function runChecks(): HealthStatus
    {
        $this->results = [];
        $isHealthy = true;

        foreach ($this->checks as $check) {
            try {
                $result = $check->check();
                $this->results[$check->getName()] = $result;
                
                if (!$result->isHealthy()) {
                    $isHealthy = false;
                }
            } catch (\Exception $e) {
                $this->logger->error('Health check failed', [
                    'check' => $check->getName(),
                    'error' => $e->getMessage()
                ]);
                $isHealthy = false;
                $this->results[$check->getName()] = new HealthCheckResult(false, $e->getMessage());
            }
        }

        return new HealthStatus($isHealthy, $this->results);
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function isHealthy(): bool
    {
        return empty(array_filter(
            $this->results,
            fn($result) => !$result->isHealthy()
        ));
    }
}

class DependencyChecker
{
    private array $dependencies;
    private array $results = [];

    public function __construct(array $dependencies)
    {
        $this->dependencies = $dependencies;
    }

    public function checkDependencies(): DependencyStatus
    {
        $this->results = [];
        $isValid = true;

        foreach ($this->dependencies as $dependency) {
            $result = $dependency->validate();
            $this->results[$dependency->getName()] = $result;
            
            if (!$result->isValid()) {
                $isValid = false;
            }
        }

        return new DependencyStatus($isValid, $this->results);
    }

    public function hasCriticalFailure(): bool
    {
        return !empty(array_filter(
            $this->results,
            fn($result) => $result->isCritical() && !$result->isValid()
        ));
    }
}

class SecurityChecker
{
    private array $checkers;
    private LoggerInterface $logger;
    private NotificationManager $notifications;

    public function __construct(
        array $checkers,
        LoggerInterface $logger,
        NotificationManager $notifications
    ) {
        $this->checkers = $checkers;
        $this->logger = $logger;
        $this->notifications = $notifications;
    }

    public function check(): SecurityReport
    {
        $issues = [];
        $criticalIssuesFound = false;

        foreach ($this->checkers as $checker) {
            try {
                $result = $checker->check();
                if (!$result->isSecure()) {
                    $issues[$checker->getName()] = $result->getIssues();
                    
                    if ($result->hasCriticalIssues()) {
                        $criticalIssuesFound = true;
                        $this->handleCriticalIssues($result->getIssues());
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Security check failed', [
                    'checker' => $checker->getName(),
                    'error' => $e->getMessage()
                ]);
                $issues[$checker->getName()] = [$e->getMessage()];
            }
        }

        return new SecurityReport($issues, $criticalIssuesFound);
    }

    private function handleCriticalIssues(array $issues): void
    {
        $this->logger->critical('Critical security issues found', [
            'issues' => $issues
        ]);
        
        $this->notifications->sendSecurityAlert($issues);
    }
}

class ConsistencyChecker
{
    private array $validators;
    private LoggerInterface $logger;

    public function __construct(array $validators, LoggerInterface $logger)
    {
        $this->validators = $validators;
        $this->logger = $logger;
    }

    public function check(array $data): ConsistencyReport
    {
        $inconsistencies = [];

        foreach ($this->validators as $validator) {
            try {
                $result = $validator->validate($data);
                if (!$result->isConsistent()) {
                    $inconsistencies[$validator->getName()] = $result->getInconsistencies();
                }
            } catch (\Exception $e) {
                $this->logger->error('Consistency check failed', [
                    'validator' => $validator->getName(),
                    'error' => $e->getMessage()
                ]);
                $inconsistencies[$validator->getName()] = [$e->getMessage()];
            }
        }

        return new ConsistencyReport(empty($inconsistencies), $inconsistencies);
    }

    public function validate(array $data): bool
    {
        return $this->check($data)->isConsistent();
    }
}
