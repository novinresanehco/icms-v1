<?php

namespace App\Core\Cache;

class DistributedCacheManager
{
    private array $nodes = [];
    private ConsistentHashing $hasher;
    private FailureDetector $failureDetector;
    private ReplicationManager $replicationManager;

    public function __construct(array $config)
    {
        $this->hasher = new ConsistentHashing($config['virtual_nodes'] ?? 64);
        $this->failureDetector = new FailureDetector();
        $this->replicationManager = new ReplicationManager($config['replication_factor'] ?? 2);
        
        foreach ($config['nodes'] as $node) {
            $this->addNode($node);
        }
    }

    public function get(string $key)
    {
        $nodes = $this->getNodesForKey($key);
        
        foreach ($nodes as $node) {
            if ($value = $this->getFromNode($node, $key)) {
                $this->repairIfNeeded($key, $value, $nodes);
                return $value;
            }
        }
        
        return null;
    }

    public function set(string $key, $value): bool
    {
        $nodes = $this->getNodesForKey($key);
        $success = true;

        foreach ($nodes as $node) {
            if (!$this->setOnNode($node, $key, $value)) {
                $success = false;
                break;
            }
        }

        if (!$success) {
            $this->revertSet($key, $nodes);
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        $nodes = $this->getNodesForKey($key);
        $success = true;

        foreach ($nodes as $node) {
            if (!$this->deleteFromNode($node, $key)) {
                $success = false;
            }
        }

        return $success;
    }

    private function addNode(array $nodeConfig): void
    {
        $node = new CacheNode($nodeConfig);
        $this->nodes[$node->getId()] = $node;
        $this->hasher->addNode($node->getId());
    }

    private function getNodesForKey(string $key): array
    {
        $nodeIds = $this->hasher->getNodesForKey($key, $this->replicationManager->getReplicationFactor());
        return array_intersect_key($this->nodes, array_flip($nodeIds));
    }

    private function getFromNode(CacheNode $node, string $key)
    {
        try {
            if (!$this->failureDetector->isAvailable($node)) {
                return null;
            }

            $value = $node->get($key);
            $this->failureDetector->recordSuccess($node);
            return $value;

        } catch (\Exception $e) {
            $this->failureDetector->recordFailure($node);
            return null;
        }
    }

    private function setOnNode(CacheNode $node, string $key, $value): bool
    {
        try {
            if (!$this->failureDetector->isAvailable($node)) {
                return false;
            }

            $success = $node->set($key, $value);
            $this->failureDetector->recordSuccess($node);
            return $success;

        } catch (\Exception $e) {
            $this->failureDetector->recordFailure($node);
            return false;
        }
    }

    private function deleteFromNode(CacheNode $node, string $key): bool
    {
        try {
            if (!$this->failureDetector->isAvailable($node)) {
                return false;
            }

            $success = $node->delete($key);
            $this->failureDetector->recordSuccess($node);
            return $success;

        } catch (\Exception $e) {
            $this->failureDetector->recordFailure($node);
            return false;
        }
    }

    private function repairIfNeeded(string $key, $value, array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($this->failureDetector->isAvailable($node) && !$node->get($key)) {
                $this->setOnNode($node, $key, $value);
            }
        }
    }

    private function revertSet(string $key, array $nodes): void
    {
        foreach ($nodes as $node) {
            try {
                $node->delete($key);
            } catch (\Exception $e) {
                // Log but continue with other nodes
                continue;
            }
        }
    }
}

class ConsistentHashing
{
    private array $ring = [];
    private int $virtualNodes;

    public function __construct(int $virtualNodes = 64)
    {
        $this->virtualNodes = $virtualNodes;
    }

    public function addNode(string $nodeId): void
    {
        for ($i = 0; $i < $this->virtualNodes; $i++) {
            $hash = $this->hash($nodeId . $i);
            $this->ring[$hash] = $nodeId;
        }
        ksort($this->ring);
    }

    public function removeNode(string $nodeId): void
    {
        for ($i = 0; $i < $this->virtualNodes; $i++) {
            $hash = $this->hash($nodeId . $i);
            unset($this->ring[$hash]);
        }
    }

    public function getNodesForKey(string $key, int $count): array
    {
        if (empty($this->ring)) {
            return [];
        }

        $hash = $this->hash($key);
        $nodes = [];
        $seen = [];

        foreach ($this->ring as $nodeHash => $nodeId) {
            if ($nodeHash >= $hash && !isset($seen[$nodeId])) {
                $nodes[] = $nodeId;
                $seen[$nodeId] = true;
                if (count($nodes) === $count) {
                    break;
                }
            }
        }

        if (count($nodes) < $count) {
            foreach ($this->ring as $nodeId) {
                if (!isset($seen[$nodeId])) {
                    $nodes[] = $nodeId;
                    $seen[$nodeId] = true;
                    if (count($nodes) === $count) {
                        break;
                    }
                }
            }
        }

        return $nodes;
    }

    private function hash(string $key): string
    {
        return hash('md5', $key);
    }
}

class FailureDetector
{
    private array $nodeStatus = [];
    private int $threshold = 3;
    private int $recoveryTime = 30;

    public function recordSuccess(CacheNode $node): void
    {
        $nodeId = $node->getId();
        $this->nodeStatus[$nodeId] = [
            'failures' => 0,
            'last_success' => time()
        ];
    }

    public function recordFailure(CacheNode $node): void
    {
        $nodeId = $node->getId();
        if (!isset($this->nodeStatus[$nodeId])) {
            $this->nodeStatus[$nodeId] = ['failures' => 0];
        }
        $this->nodeStatus[$nodeId]['failures']++;
        $this->nodeStatus[$nodeId]['last_failure'] = time();
    }

    public function isAvailable(CacheNode $node): bool
    {
        $nodeId = $node->getId();
        if (!isset($this->nodeStatus[$nodeId])) {
            return true;
        }

        $status = $this->nodeStatus[$nodeId];

        if ($status['failures'] >= $this->threshold) {
            $timeSinceLastFailure = time() - ($status['last_failure'] ?? 0);
            return $timeSinceLastFailure > $this->recoveryTime;
        }

        return true;
    }
}

class CacheNode
{
    private string $id;
    private string $host;
    private int $port;
    private $connection;

    public function __construct(array $config)
    {
        $this->id = $config['id'];
        $this->host = $config['host'];
        $this->port = $config['port'];
    }

    public function getId(): string
    {
        return $this->