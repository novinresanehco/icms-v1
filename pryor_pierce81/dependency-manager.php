<?php

namespace App\Core\Dependency;

class DependencyManager implements DependencyInterface
{
    private DependencyStore $store;
    private SecurityScanner $scanner;
    private VersionControl $versionControl;
    private DependencyLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        DependencyStore $store,
        SecurityScanner $scanner,
        VersionControl $versionControl,
        DependencyLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->store = $store;
        $this->scanner = $scanner;
        $this->versionControl = $versionControl;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function validateDependencies(DependencyContext $context): DependencyResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $dependencies = $this->loadDependencies($context);
            $this->validateVersions($dependencies);
            $this->scanForVulnerabilities($dependencies);
            $this->checkCompatibility($dependencies);

            $result = new DependencyResult([
                'validationId' => $validationId,
                'dependencies' => $dependencies,
                'securityStatus' => $this->getSecurityStatus($dependencies),
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (DependencyException $e) {
            DB::rollBack();
            $this->handleDependencyFailure($e, $validationId);
            throw new CriticalDependencyException($e->getMessage(), $e);
        }
    }

    private function validateVersions(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            if (!$this->versionControl->isValidVersion($dependency)) {
                $this->emergency->handleInvalidVersion($dependency);
                throw new InvalidVersionException(
                    "Invalid version for dependency: {$dependency->getName()}"
                );
            }
        }
    }

    private function scanForVulnerabilities(array $dependencies): void
    {
        $vulnerabilities = $this->scanner->scan($dependencies);
        
        if (!empty($vulnerabilities)) {
            $criticalVulnerabilities = array_filter(
                $vulnerabilities,
                fn($v) => $v->isCritical()
            );
            
            if (!empty($criticalVulnerabilities)) {
                $this->emergency->handleCriticalVulnerabilities($criticalVulnerabilities);
                throw new SecurityVulnerabilityException(
                    'Critical security vulnerabilities detected',
                    ['vulnerabilities' => $criticalVulnerabilities]
                );
            }
        }
    }

    private function handleDependencyFailure(DependencyException $e, string $validationId): void
    {
        $this->logger->logFailure($e, $validationId);
        
        if ($e->isCritical()) {
            $this->emergency->initiateEmergencyProtocol();
            $this->alerts->dispatchCriticalAlert(
                new DependencyFailureAlert($e, $validationId)
            );
        }
    }
}
