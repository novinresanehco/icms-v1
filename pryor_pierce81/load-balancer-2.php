<?php

namespace App\Core\Performance;

class LoadBalancingService implements LoadBalancerInterface
{
    private CapacityMonitor $capacityMonitor;
    private LoadDistributor $distributor;
    private HealthChecker $healthChecker;
    private PerformanceAnalyzer $performanceAnalyzer;
    private LoadBalancerLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        CapacityMonitor $capacityMonitor,
        LoadDistributor $distributor,
        HealthChecker $healthChecker,
        PerformanceAnalyzer $performanceAnalyzer,
        LoadBalancerLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->capacityMonitor = $capacityMonitor;
        $this->distributor = $distributor;
        $this->healthChecker = $healthChecker;
        $this->performanceAnalyzer = $performanceAnalyzer;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function balanceLoad(LoadRequest $request): LoadBalanceResult
    {
        $operationId = $this->initializeOperation($request);
        
        try {
            DB::beginTransaction();

            $this->validateRequest($request);
            $capacity = $this->checkSystemCapacity();
            $healthStatus = $this->verifySystemHealth();

            if (!$this->canHandleLoad($capacity, $request)) {
                throw new CapacityExceededException('System capacity exceeded');
            }

            $distribution = $this->distributor->distribute(
                $request,
                $capacity,
                $healthStatus
            );

            $this->verifyDistribution($distribution);
            $this->monitorPerformance($distribution);

            $result = new LoadBalanceResult([
                'operationId' => $operationId,
                'distribution' => $distribution,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (LoadBalancerException $e) {
            DB::rollBack();
            $this->handleBalancerFailure($e, $operationId);
            throw new CriticalLoadException($e->getMessage(), $e);
        }
    }

    private function checkSystemCapacity(): SystemCapacity
    {
        $capacity = $this->capacityMonitor->getCurrentCapacity();
        
        if ($capacity->isNearThreshold()) {
            $this->emergency->handleCapacityWarning($capacity);
        }
        
        return $capacity;
    }

    private function verifySystemHealth(): HealthStatus
    {
        $status = $this->healthChecker->checkHealth();
        
        if (!$status->isHealthy()) {
            $this->emergency->handleUnhealthySystem($status);
            throw new UnhealthySystemException('System health check failed');
        }
        
        return $status;
    }

    private function monitorPerformance(LoadDistribution $distribution): void
    {
        $performance = $this->performanceAnalyzer->analyze($distribution);
        
        if ($performance->hasIssues()) {
            $this->emergency->handlePerformanceIssues($performance);
        }
    }

    private function handleBalancerFailure(
        LoadBalancerException $e,
        string $operationId
    ): void {
        $this->logger->logFailure($e, $operationId);
        $this->emergency->handleLoadBalancerFailure($e);

        if ($e->isCritical()) {
            $this->emergency->initiateCriticalRecovery();
        }
    }
}
