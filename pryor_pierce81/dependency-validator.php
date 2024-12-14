<?php

namespace App\Core\Dependency;

class DependencyValidationService implements DependencyInterface
{
    private DependencyGraph $dependencyGraph;
    private CyclicDetector $cyclicDetector;
    private VersionValidator $versionValidator;
    private SecurityChecker $securityChecker;
    private DependencyLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        DependencyGraph $dependencyGraph,
        CyclicDetector $cyclicDetector,
        VersionValidator $versionValidator,
        SecurityChecker $securityChecker,
        DependencyLogger $logger,
        AlertSystem $alerts
    ) {
        $this->dependencyGraph = $dependencyGraph;
        $this->cyclicDetector = $cyclicDetector;
        $this->versionValidator = $versionValidator;
        $this->securityChecker = $securityChecker;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function validateDependencies(DependencyContext $context): ValidationResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $graph = $this->dependencyGraph->buildGraph($context);
            
            $this->validateGraph($graph);
            $this->detectCycles($graph);
            $this->validateVersions($graph);
            $this->checkSecurity($graph);

            $result = new ValidationResult([
                'validationId' => $validationId,
                'graph' => $graph,
                'status' => ValidationStatus::PASSED,
                'metrics' => $this->collectMetrics($graph),
                'timestamp' => now()
            ]);

            DB::commit();
            $this->finalizeValidation($result);

            return $result;

        } catch (DependencyException $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $validationId);
            throw new CriticalDependencyException($e->getMessage(), $e);
        }
    }

    private function validateGraph(DependencyGraph $graph): void
    {
        $invalidNodes = $graph->validateNodes();
        
        if (!empty($invalidNodes)) {
            throw new InvalidGraphException(
                'Invalid dependency graph detected',
                ['invalid_nodes' => $invalidNodes]
            );
        }
    }

    private function detectCycles(DependencyGraph $graph): void
    {
        $cycles = $this->cyclicDetector->detectCycles($graph);
        
        if (!empty($cycles)) {
            throw new CyclicDependencyException(
                'Cyclic dependencies detected',
                ['cycles' => $cycles]
            );
        }
    }

    private function validateVersions(DependencyGraph $graph): void
    {
        $versionIssues = $this->versionValidator->validate($graph);
        
        if (!empty($versionIssues)) {
            throw new VersionMismatchException(
                'Version validation failed',
                ['issues' => $versionIssues]
            );
        }
    }

    private function checkSecurity(DependencyGraph $graph): void
    {
        $vulnerabilities = $this->securityChecker->check($graph);
        
        if (!empty($vulnerabilities)) {
            throw new SecurityVulnerabilityException(
                'Security vulnerabilities detected in dependencies',
                ['vulnerabilities' => $vulnerabilities]
            );
        }
    }

    private function finalizeValidation(ValidationResult $result): void
    {
        $this->logger->logValidation($result);
        
        if ($result->hasWarnings()) {
            $this->alerts->dispatch(
                new DependencyAlert(
                    'Dependency validation warnings detected',
                    ['warnings' => $result->getWarnings()]
                )
            );
        }
    }
}
