<?php

namespace App\Core\Analysis;

class CodeAnalyzer implements CodeAnalyzerInterface
{
    private Parser $parser;
    private RuleEngine $rules;
    private MetricsCollector $metrics;
    private ValidationService $validator;

    protected function collectMetrics($ast): array
    {
        return [
            'complexity' => $this->metrics->calculateComplexity($ast),
            'loc' => $this->metrics->calculateLoc($ast),
            'dependencies' => $this->metrics->analyzeDependencies($ast),
            'cohesion' => $this->metrics->calculateCohesion($ast),
            'coupling' => $this->metrics->calculateCoupling($ast)
        ];
    }

    protected function compileResults(
        StructureAnalysisResult $structure,
        QualityAnalysisResult $quality,
        SecurityAnalysisResult $security,
        array $metrics,
        string $operationId
    ): AnalysisResult {
        return new AnalysisResult([
            'structure' => $structure->toArray(),
            'quality' => $quality->toArray(),
            'security' => $security->toArray(),
            'metrics' => $metrics,
            'operation_id' => $operationId,
            'timestamp' => time(),
            'status' => $this->determineAnalysisStatus($structure, $quality, $security)
        ]);
    }

    protected function determineAnalysisStatus(
        StructureAnalysisResult $structure,
        QualityAnalysisResult $quality,
        SecurityAnalysisResult $security
    ): string {
        if ($security->hasCriticalVulnerabilities()) {
            return AnalysisResult::STATUS_CRITICAL;
        }

        if (!$structure->isValid() || !$quality->isValid() || !$security->isValid()) {
            return AnalysisResult::STATUS_FAILED;
        }

        if ($structure->hasWarnings() || $quality->hasWarnings() || $security->hasWarnings()) {
            return AnalysisResult::STATUS_WARNING;
        }

        return AnalysisResult::STATUS_PASSED;
    }

    protected function handleAnalysisFailure(\Throwable $e, string $operationId): void
    {
        $this->metrics->recordFailure([
            'type' => 'analysis_failure',
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'timestamp' => time(),
            'severity' => 'ERROR'
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->escalateFailure($e, $operationId);
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof SecurityVulnerabilityException ||
               $e instanceof CriticalStructureException;
    }

    protected function escalateFailure(\Throwable $e, string $operationId): void
    {
        $this->metrics->triggerCriticalAlert([
            'type' => 'critical_analysis_failure',
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'severity' => 'CRITICAL'
        ]);
    }
}

class StructureAnalysisResult {
    private array $violations;
    private bool $valid;
    private bool $hasWarnings;

    public function __construct(array $violations) {
        $this->violations = $violations;
        $this->valid = empty(array_filter($violations, fn($v) => $v['severity'] === 'ERROR'));
        $this->hasWarnings = !empty(array_filter($violations, fn($v) => $v['severity'] === 'WARNING'));
    }

    public function isValid(): bool {
        return $this->valid;
    }

    public function hasWarnings(): bool {
        return $this->hasWarnings;
    }

    public function toArray(): array {
        return [
            'violations' => $this->violations,
            'valid' => $this->valid,
            'has_warnings' => $this->hasWarnings
        ];
    }
}

class QualityAnalysisResult {
    private array $issues;
    private bool $valid;
    private bool $hasWarnings;

    public function __construct(array $issues) {
        $this->issues = $issues;
        $this->valid = empty(array_filter($issues, fn($i) => $i['severity'] === 'ERROR'));
        $this->hasWarnings = !empty(array_filter($issues, fn($i) => $i['severity'] === 'WARNING'));
    }

    public function isValid(): bool {
        return $this->valid;
    }

    public function hasWarnings(): bool {
        return $this->hasWarnings;
    }

    public function toArray(): array {
        return [
            'issues' => $this->issues,
            'valid' => $this->valid,
            'has_warnings' => $this->hasWarnings
        ];
    }
}

class SecurityAnalysisResult {
    private array $vulnerabilities;
    private bool $valid;
    private bool $hasWarnings;
    private bool $hasCritical;

    public function __construct(array $vulnerabilities) {
        $this->vulnerabilities = $vulnerabilities;
        $this->hasCritical = !empty(array_filter($vulnerabilities, fn($v) => $v['severity'] === 'CRITICAL'));
        $this->valid = empty(array_filter($vulnerabilities, fn($v) => $v['severity'] === 'ERROR'));
        $this->hasWarnings = !empty(array_filter($vulnerabilities, fn($v) => $v['severity'] === 'WARNING'));
    }

    public function isValid(): bool {
        return $this->valid;
    }

    public function hasWarnings(): bool {
        return $this->hasWarnings;
    }

    public function hasCriticalVulnerabilities(): bool {
        return $this->hasCritical;
    }

    public function toArray(): array {
        return [
            'vulnerabilities' => $this->vulnerabilities,
            'valid' => $this->valid,
            'has_warnings' => $this->hasWarnings,
            'has_critical' => $this->hasCritical
        ];
    }
}
