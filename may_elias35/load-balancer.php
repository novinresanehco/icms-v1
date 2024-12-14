<?php

namespace App\Core\LoadBalancing;

class LoadBalancer
{
    private NodeRegistry $registry;
    private HealthChecker $healthChecker;
    private MetricsCollector $metrics;
    private LoadDistributor $distributor;

    public function __construct(
        NodeRegistry $registry,
        HealthChecker $healthChecker,
        MetricsCollector $metrics,
        LoadDistributor $distributor
    ) {
        $this->registry = $registry;
        $this->healthChecker = $healthChecker;
        $this->metrics = $metrics;
        $this->distributor = $distributor;
    }

    public function getOptimalNode(Service $service): Node
    {
        $nodes = $this->registry->getNodesForService($service);
        $healthyNodes = $this->filterHealthyNodes($nodes);
        
        if (empty($healthyNodes)) {
            throw new NoHealthyNodesException($service->getName());
        }

        $selectedNode = $this->distributor->selectNode($healthyNodes);
        $this->metrics->recordNodeSelection($selectedNode);
        
        return $selectedNode;
    }

    public function registerNode(Node $node): void
    {
        $this->registry->register($node);
        $this->healthChecker->startMonitoring($node);
    }

    public function removeNode(Node $node): void
    {
        $this->registry->remove($node);
        $this->healthChecker->stopMonitoring($node);
        $this->metrics->recordNodeRemoval($node);
    }

    public function handleNodeFailure(Node $node): void
    {
        $this->metrics->recordNodeFailure($node);
        $this->healthChecker->markNodeUnhealthy($node);
        
        if ($this->shouldRemoveNode($node)) {
            $this->removeNode($node);
        }
    }

    protected function filterHealthyNodes(array $nodes): array
    {
        return array_filter($nodes, function($node) {
            return $this->healthChecker->isHealthy($node) &&
                   $this->isNodeCapacityAvailable($node);
        });
    }

    protected function isNodeCapacityAvailable(Node $node): bool
    {
        $currentLoad = $this->metrics->getCurrentLoad($node);
        return $currentLoad < $node->getMaxCapacity();
    }

    protected function shouldRemoveNode(Node $node): bool
    {
        $failureRate = $this->metrics->getNodeFailureRate($node);
        return $failureRate > $node->getFailureThreshold();
    }

    public function getNodeMetrics(Node $node): array
    {
        return [
            'requests_per_second' => $this->metrics->getRequestRate($node),
            'average_response_time' => $this->metrics->getAverageResponseTime($node),
            'error_rate' => $this->metrics->getErrorRate($node),
            'current_load' => $this->metrics->getCurrentLoad($node),
            'health_status' => $this->healthChecker->getNodeStatus($node)
        ];
    }
}
