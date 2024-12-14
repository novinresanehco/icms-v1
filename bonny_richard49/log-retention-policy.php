<?php

namespace App\Core\Logging\Retention;

class RetentionPolicy implements RetentionPolicyInterface
{
    private array $config;
    private LogAnalyzer $analyzer;
    private RuleEngine $ruleEngine;

    public function __construct(
        array $config,
        LogAnalyzer $analyzer,
        RuleEngine $ruleEngine
    ) {
        $this->config = $config;
        $this->analyzer = $analyzer;
        $this->ruleEngine = $ruleEngine;
    }

    public function determineAction(LogEntry $log): RetentionAction
    {
        // Apply retention rules in order
        foreach ($this->getRules() as $rule) {
            if ($rule->applies($log)) {
                return $rule->getAction();
            }
        }

        // Default action if no rules match
        return RetentionAction::RETAIN;
    }

    public function getRetentionAge(): \DateInterval
    {
        return \DateInterval::createFromDateString(
            $this->config['retention_period'] ?? '90 days'
        );
    }

    public function getBatchSize(): int
    {
        return $this->config['batch_size'] ?? 1000;
    }

    public function getExcludedTypes(): array
    {
        return $this->config['excluded_types'] ?? [];
    }

    protected function getRules(): array
    {
        return [
            new CriticalLogRetentionRule($this->config['critical_retention'] ?? '365 days'),
            new SecurityLogRetentionRule($this->config['security_retention'] ?? '180 days'),
            new CompressibleLogRule($this->analyzer),
            new StandardLogRetentionRule($this->config['standard_retention'] ?? '90 days'),
            new ArchivalRule($this->config['archival_criteria'] ?? [])
        ];
    }
}

abstract class RetentionRule
{
    protected array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    abstract public function applies(LogEntry $log): bool;
    abstract public function getAction(): RetentionAction;
}

class CriticalLogRetentionRule extends RetentionRule
{
    private string $retentionPeriod;

    public function __construct(string $retentionPeriod)
    {
        $this->retentionPeriod = $retentionPeriod;
    }

    public function applies(LogEntry $log): bool
    {
        // Check if log is critical
        if (!in_array($log->level, ['emergency', 'alert', 'critical'])) {
            return false;
        }

        // Check age against retention period
        $retentionDate = now()->sub(\DateInterval::createFromDateString($this->retentionPeriod));
        return $log->created_at->lt($retentionDate);
    }

    public function getAction(): RetentionAction
    {
        return RetentionAction::ARCHIVE;
    }
}

class SecurityLogRetentionRule extends RetentionRule
{
    private string $retentionPeriod;

    public function __construct(string $retentionPeriod)
    {
        $this->retentionPeriod = $retentionPeriod;
    }

    public function applies(LogEntry $log): bool
    {
        // Check if log is security-related
        if (!$this->isSecurityLog($log)) {
            return false;
        }

        // Check age against retention period
        $retentionDate = now()->sub(\DateInterval::createFromDateString($this->retentionPeriod));
        return $log->created_at->lt($retentionDate);
    }

    public function getAction(): RetentionAction
    {
        return RetentionAction::ARCHIVE;
    }

    private function isSecurityLog(LogEntry $log): bool
    {
        return $log->hasTag('security') ||
               str_contains(strtolower($log->message), 'security') ||
               isset($log->context['security_event']);
    }
}

class CompressibleLogRule extends RetentionRule
{
    private LogAnalyzer $analyzer;

    public function __construct(LogAnalyzer $analyzer)
    {
        $this->analyzer = $analyzer;
    }

    public function applies(LogEntry $log): bool
    {
        // Check if log is already compressed
        if ($log->isCompressed()) {
            return false;
        }

        // Check if log meets compression criteria
        return $this->analyzer->isCompressible($log) &&
               $log->size > ($this->config['min_size_for_compression'] ?? 1024);
    }

    public function getAction(): RetentionAction
    {
        return RetentionAction::COMPRESS;
    }
}

class StandardLogRetentionRule extends RetentionRule
{
    private string $retentionPeriod;

    public function __construct(string $retentionPeriod)
    {
        $this->retentionPeriod = $retentionPeriod;
    }

    public function applies(LogEntry $log): bool
    {
        $retentionDate = now()->sub(\DateInterval::createFromDateString($this->retentionPeriod));
        return $log->created_at->lt($retentionDate);
    }

    public function getAction(): RetentionAction
    {
        return RetentionAction::DELETE;
    }
}

class ArchivalRule extends RetentionRule
{
    private array $criteria;

    public function __construct(array $criteria)
    {
        $this->criteria = $criteria;
    }

    public function applies(LogEntry $log): bool
    {
        foreach ($this->criteria as $criterion) {
            if (!$this->meetsCriterion($log, $criterion)) {
                return false;
            }
        }
        return true;
    }

    public function getAction(): RetentionAction
    {
        return RetentionAction::ARCHIVE;
    }

    private function meetsCriterion(LogEntry $log, array $criterion): bool
    {
        switch ($criterion['type']) {
            case 'age':
                return $this->meetsAgeCriterion($log, $criterion);
            case 'size':
                return $this->meetsSizeCriterion($log, $criterion);
            case 'importance':
                return $this->meetsImportanceCriterion($log, $criterion);
            default:
                return false;
        }
    }
}
