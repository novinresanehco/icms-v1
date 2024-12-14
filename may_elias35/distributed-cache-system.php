<?php

namespace App\Core\Media\Cache\Distributed;

use Illuminate\Support\Facades\Redis;
use App\Core\Media\Models\Media;
use App\Core\Media\Cache\Contracts\CacheNodeInterface;

class DistributedCacheManager
{
    protected NodeRegistry $nodeRegistry;
    protected ConsistentHasher $hasher;
    protected CacheReplicator $replicator;
    protected FailoverHandler $failover;

    public function __construct(
        NodeRegistry $nodeRegistry,
        ConsistentHasher $hasher,
        CacheReplicator $replicator,
        FailoverHandler $failover
    ) {
        $this->nodeRegistry = $nodeRegistry;
        $this->hasher = $hasher;
        $this->replicator = $replicator;
        $this->failover = $failover;
    }

    public function get(string $key): mixed
    {
        $nodes = $this->getResponsibleNodes($key);
        
        foreach ($nodes as $node) {
            try {
                if ($value = $node->get($key)) {
                    $this->repairInconsistency($key, $value, $nodes);
                    return $value;
                }
            } catch (NodeFailureException $e) {
                $this->handleNodeFailure($node, $e);
            }
        }

        return null;
    }

    public function set(string $key, mixed $value, int $ttl = null): bool
    {
        $nodes = $this->getResponsibleNodes($key);
        $success = true;

        foreach ($nodes as $node) {
            try {
                if (!$node->set($key, $value, $ttl)) {
                    $success = false;
                    $this->logWriteFailure($node, $key);
                }
            } catch (NodeFailureException $e) {
                $success = false;
                $this->handleNodeFailure($node, $e);
            }
        }

        return $success;
    }

    protected function getResponsibleNodes(string $key): array
    {
        $primaryNode = $this->hasher->getNode($key);
        $replicaNodes = $this->replicator->getReplicaNodes($primaryNode);
        
        return array_merge([$primaryNode], $replicaNodes);
    }

    protected function repairInconsistency(string $key, mixed $value, array $nodes): void
    {
        foreach ($nodes as $node) {
            try {
                if (!$node->has($key) || $node->get($key) !== $value) {
                    $node->set($key, $value);
                    $this->logInconsistencyRepair($node, $key);
                }
            } catch (NodeFailureException $e) {
                $this->handleNodeFailure($node, $e);
            }
        }
    }

    protected function handleNodeFailure(CacheNodeInterface $node, NodeFailureException $e): void
    {
        $this->nodeRegistry->markNodeDown($node);
        $this->failover->handleFailure($node);
        $this->logNodeFailure($node, $e);
    }
}

class ConsistentHasher
{
    protected array $ring = [];
    protected int $replicas = 128;
    protected array $nodes = [];

    public function addNode(CacheNodeInterface $node): void
    {
        $this->nodes[] = $node;
        
        // Add virtual nodes
        for ($i = 0; $i < $this->replicas; $i++) {
            $hash = $this->hash($node->getId() . $i);
            $this->ring[$hash] = $node;
        }
        
        ksort($this->ring, SORT_NUMERIC);
    }

    public function removeNode(CacheNodeInterface $node): void
    {
        $this->nodes = array_filter(
            $this->nodes,
            fn($n) => $n->getId() !== $node->getId()
        );
        
        // Remove virtual nodes
        for ($i = 0; $i < $this->replicas; $i++) {
            $hash = $this->hash($node->getId() . $i);
            unset($this->ring[$hash]);
        }
    }

    public function getNode(string $key): CacheNodeInterface
    {
        $hash = $this->hash($key);
        
        // Find first node after hash
        foreach ($this->ring as $nodeHash => $node) {
            if ($nodeHash >= $hash) {
                return $node;
            }
        }
        
        // Wrap around to first node
        return reset($this->ring);
    }

    protected function hash(string $key): string
    {
        return hash('md5', $key);
    }
}

class CacheReplicator
{
    protected int $replicationFactor = 2;
    protected NodeRegistry $nodeRegistry;

    public function __construct(NodeRegistry $nodeRegistry)
    {
        $this->nodeRegistry = $nodeRegistry;
    }

    public function getReplicaNodes(CacheNodeInterface $primaryNode): array
    {
        $allNodes = $this->nodeRegistry->getHealthyNodes();
        $replicaNodes = [];
        
        foreach ($allNodes as $node) {
            if ($node->getId() !== $primaryNode->getId()) {
                $replicaNodes[] = $node;
                
                if (count($replicaNodes) >= $this->replicationFactor) {
                    break;
                }
            }
        }
        
        return $replicaNodes;
    }

    public function replicateData(string $key, mixed $value, array $nodes): void
    {
        foreach ($nodes as $node) {
            try {
                $node->set($key, $value);
            } catch (NodeFailureException $e) {
                $this->handleReplicationFailure($node, $key, $e);
            }
        }
    }

    protected function handleReplicationFailure(CacheNodeInterface $node, string $key, NodeFailureException $e): void
    {
        $this->nodeRegistry->markNodeDown($node);
        
        // Find new replica node
        $newNode = $this->findNewReplicaNode($node);
        if ($newNode) {
            $this->replicateToNewNode($newNode, $key);
        }
    }
}

class FailoverHandler
{
    protected NodeRegistry $nodeRegistry;
    protected ConsistentHasher $hasher;
    protected DataRebalancer $rebalancer;

    public function handleFailure(CacheNodeInterface $failedNode): void
    {
        // Mark node as failed
        $this->nodeRegistry->markNodeDown($failedNode);
        
        // Rebalance data
        $this->rebalancer->rebalanceData($failedNode);
        
        // Update hash ring
        $this->hasher->removeNode($failedNode);
        
        // Notify monitoring
        $this->notifyMonitoring($failedNode);
    }

    public function handleRecovery(CacheNodeInterface $recoveredNode): void
    {
        // Verify node health
        if ($this->verifyNodeHealth($recoveredNode)) {
            // Mark node as up
            $this->nodeRegistry->markNodeUp($recoveredNode);
            
            // Add back to hash ring
            $this->hasher->addNode($recoveredNode);
            
            // Rebalance data to include recovered node
            $this->rebalancer->rebalanceWithNode($recoveredNode);
        }
    }

    protected function verifyNodeHealth(CacheNodeInterface $node): bool
    {
        try {
            $node->ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

class NodeHealthMonitor
{
    protected NodeRegistry $nodeRegistry;
    protected MetricsCollector $metrics;
    protected AlertManager $alerts;

    public function monitor(): void
    {
        foreach ($this->nodeRegistry->getAllNodes() as $node) {
            try {
                $this->checkNodeHealth($node);
                $this->collectNodeMetrics($node);
            } catch (NodeFailureException $e) {
                $this->handleUnhealthyNode($node, $e);
            }
        }
    }

    protected function checkNodeHealth(CacheNodeInterface $node): void
    {
        $healthChecks = [
            $this->checkConnectivity($node),
            $this->checkLatency($node),
            $this->checkMemoryUsage($node),
            $this->checkErrorRate($node)
        ];

        if (in_array(false, $healthChecks, true)) {
            throw new NodeUnhealthyException($node);
        }
    }

    protected function collectNodeMetrics(CacheNodeInterface $node): void
    {
        $this->metrics->record([
            'node_id' => $node->getId(),
            'latency' => $this->measureLatency($node),
            'memory_usage' => $node->getMemoryUsage(),
            'hit_rate' => $node->getHitRate(),
            'error_rate' => $node->getErrorRate()
        ]);
    }
}
