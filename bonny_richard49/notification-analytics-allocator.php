<?php

namespace App\Core\Notification\Analytics\Allocator;

class ResourceAllocator
{
    private array $resources = [];
    private array $allocations = [];
    private array $metrics = [];

    public function registerResource(string $name, array $config): void
    {
        $this->resources[$name] = array_merge([
            'capacity' => 100,
            'priority' => 1,
            'available' => true
        ], $config);
    }

    public function allocate(string $resourceType, int $amount, array $constraints = []): ?string
    {
        $availableResources = $this->findAvailableResources($resourceType, $amount, $constraints);
        if (empty($availableResources)) {
            return null;
        }

        $resourceId = array_key_first($availableResources);
        $allocationId = $this->createAllocation($resourceId, $amount);

        $this->updateMetrics($resourceType, $amount);

        return $allocationId;
    }

    public function release(string $allocationId): bool
    {
        if (!isset($this->allocations[$allocationId])) {
            return false;
        }

        $allocation = $this->allocations[$allocationId];
        $this->resources[$allocation['resource_id']]['capacity'] += $allocation['amount'];
        unset($this->allocations[$allocationId]);

        return true;
    }

    public function getResourceStatus(string $resourceId): ?array
    {
        return $this->resources[$resourceId] ?? null;
    }

    public function getAllocationStatus(string $allocationId): ?array
    {
        return $this->allocations[$allocationId] ?? null;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function findAvailableResources(string $resourceType, int $amount, array $constraints): array
    {
        return array_filter($this->resources, function($resource) use ($amount, $constraints) {
            return $resource['available'] && 
                   $resource['capacity'] >= $amount &&
                   $this->meetsConstraints($resource, $constraints);
        });
    }

    private function meetsConstraints(array $resource, array $constraints): bool
    {
        foreach ($constraints as $key => $value) {
            if (!isset($resource[$key]) || $resource[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    private function createAllocation(string $resourceId, int $amount): string
    {
        $allocationId = uniqid('alloc_', true);
        
        $this->allocations[$allocationId] = [
            'resource_id' => $resourceId,
            'amount' => $amount,
            'timestamp' => time()
        ];

        $this->resources[$resourceId]['capacity'] -= $amount;

        return $allocationId;
    }

    private function updateMetrics(string $resourceType, int $amount): void
    {
        if (!isset($this->metrics[$resourceType])) {
            $this->metrics[$resourceType] = [
                'total_allocations' => 0,
                'total_amount' => 0,
                'current_utilization' => 0
            ];
        }

        $this->metrics[$resourceType]['total_allocations']++;
        $this->metrics[$resourceType]['total_amount'] += $amount;
        $this->metrics[$resourceType]['current_utilization'] = $this->calculateUtilization($resourceType);
    }

    private function calculateUtilization(string $resourceType): float
    {
        $totalCapacity = array_sum(array_column(array_filter($this->resources, fn($r) => $r['type'] === $resourceType), 'capacity'));
        $totalAllocated = array_sum(array_column(array_filter($this->allocations, fn($a) => $this->resources[$a['resource_id']]['type'] === $resourceType), 'amount'));
        
        return $totalCapacity > 0 ? $totalAllocated / $totalCapacity : 0;
    }
}
