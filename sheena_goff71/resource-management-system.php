<?php

namespace App\Core\Resources;

class ResourceManagementSystem implements ResourceManagerInterface
{
    private ResourceAllocator $allocator;
    private ConstraintManager $constraints;
    private UsageMonitor $monitor;
    private SecurityValidator $security;
    private EmergencyHandler $emergency;

    public function __construct(
        ResourceAllocator $allocator,
        ConstraintManager $constraints,
        UsageMonitor $monitor,
        SecurityValidator $security,
        EmergencyHandler $emergency
    ) {
        $this->allocator = $allocator;
        $this->constraints = $constraints;
        $this->monitor = $monitor;
        $this->security = $security;
        $this->emergency = $emergency;
    }

    public function allocateResources(ResourceRequest $request): AllocationResult
    {
        $allocationId = $this->monitor->startAllocation();
        DB::beginTransaction();

        try {
            // Validate request
            $validation = $this->validateResourceRequest($request);
            if (!$validation->isValid()) {
                throw new ValidationException($validation->getViolations());
            }

            // Security check
            $securityCheck = $this->security->validateAllocation($request);
            if (!$securityCheck->isGranted()) {
                throw new SecurityException($securityCheck->getViolations());
            }

            // Check resource availability
            $availability = $this->allocator->checkAvailability($request);
            if (!$availability->hasResources()) {
                throw new ResourceException('Insufficient resources available');
            }

            // Allocate resources with monitoring
            $allocation = $this->executeAllocation(
                $request,
                $allocationId
            );

            // Verify allocation
            $this->verifyAllocation($allocation);

            $this->monitor->recordAllocation($allocationId, $allocation);
            DB::commit();

            return $allocation;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAllocationFailure($allocationId, $request, $e);
            throw $e;
        }
    }

    private function validateResourceRequest(ResourceRequest $request): ValidationResult
    {
        $violations = [];

        // Validate resource limits
        if (!$this->constraints->validateLimits($request)) {
            $violations[] = new LimitViolation('Resource limits exceeded');
        }

        // Validate resource constraints
        if (!$this->constraints->validateConstraints($request)) {
            $violations[] = new ConstraintViolation('Resource constraints violated');
        }

        // Validate dependencies
        if (!$this->constraints->validateDependencies($request)) {
            $violations[] = new DependencyViolation('Resource dependencies not met');
        }

        return new ValidationResult(
            valid: empty($violations),
            violations: $violations
        );
    }

    private function executeAllocation(
        ResourceRequest $request,
        string $allocationId
    ): AllocationResult {
        // Reserve resources
        $reservation = $this->allocator->reserveResources($request);

        // Apply resource constraints
        $this->constraints->applyConstraints($reservation);

        // Monitor allocation impact
        $impact = $this->monitor->measureAllocationImpact($reservation);
        if (!$impact->isAcceptable()) {
            $this->allocator->releaseReservation($reservation);
            throw new ImpactException('Resource allocation impact exceeds limits');
        }

        return new AllocationResult(
            success: true,
            allocationId: $allocationId,
            resources: $reservation->getResources(),
            metrics: $impact->getMetrics()
        );
    }

    private function verifyAllocation(AllocationResult $allocation): void
    {
        // Verify resource integrity
        if (!$this->allocator->verifyIntegrity($allocation)) {
            throw new IntegrityException('Resource allocation integrity check failed');
        }

        // Verify constraints
        if (!$this->constraints->verifyConstraints($allocation)) {
            throw new ConstraintException('Resource constraints verification failed');
        }

        // Verify security
        if (!$this->security->verifyAllocation($allocation)) {
            throw new SecurityException('Resource allocation security check failed');
        }
    }

    private function handleAllocationFailure(
        string $allocationId,
        ResourceRequest $request,
        \Exception $e
    ): void {
        // Log failure
        Log::critical('Resource allocation failed', [
            'allocation_id' => $allocationId,
            'request' => $request->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Release any held resources
        $this->allocator->releaseAllocation($allocationId);

        // Execute emergency protocols
        $this->emergency->handleAllocationFailure(
            $allocationId,
            $request,
            $e
        );
    }

    public function monitorResources(): ResourceStatus
    {
        try {
            $status = $this->monitor->getCurrentStatus();

            // Verify resource integrity
            if (!$status->hasIntegrity()) {
                throw new IntegrityException('Resource integrity compromised');
            }

            // Check resource health
            if (!$status->isHealthy()) {
                $this->handleUnhealthyResources($status);
            }

            // Analyze usage patterns
            $analysis = $this->monitor->analyzeUsagePatterns($status);
            if ($analysis->hasAnomalies()) {
                $this->handleResourceAnomalies($analysis);
            }

            return $status;

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
            throw new MonitoringException(
                'Resource monitoring failed',
                previous: $e
            );
        }
    }

    private function handleUnhealthyResources(ResourceStatus $status): void
    {
        foreach ($status->getUnhealthyResources() as $resource) {
            $this->emergency->handleUnhealthyResource($resource);
        }
    }

    private function handleResourceAnomalies(UsageAnalysis $analysis): void
    {
        foreach ($analysis->getAnomalies() as $anomaly) {
            $this->emergency->handleResourceAnomaly($anomaly);
        }
    }

    private function handleMonitoringFailure(\Exception $e): void
    {
        $this->emergency->handleMonitoringFailure([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);
    }
}
