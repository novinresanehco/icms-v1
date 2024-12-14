```php
namespace App\Core\Media\Analytics\Streaming\Backpressure;

class BackpressureManager
{
    protected ThrottleController $throttle;
    protected BufferManager $buffer;
    protected LoadBalancer $loadBalancer;
    protected MetricsCollector $metrics;

    public function __construct(
        ThrottleController $throttle,
        BufferManager $buffer,
        LoadBalancer $loadBalancer,
        MetricsCollector $metrics
    ) {
        $this->throttle = $throttle;
        $this->buffer = $buffer;
        $this->loadBalancer = $loadBalancer;
        $this->metrics = $metrics;
    }

    public function handleIncomingData(StreamData $data): void
    {
        // Check system load
        $load = $this->getCurrentLoad();
        
        if ($this->shouldApplyBackpressure($load)) {
            $this->applyBackpressureMeasures($load, $data);
        } else {
            $this->processNormally($data);
        }

        // Update metrics
        $this->metrics->record([
            'load' => $load,
            'backpressure_applied' => $this->throttle->isActive(),
            'buffer_size' => $this->buffer->size(),
            'processing_rate' => $this->calculateProcessingRate()
        ]);
    }

    protected function shouldApplyBackpressure(SystemLoad $load): bool
    {
        return $load->exceedsThreshold() || 
               $this->buffer->isNearCapacity() || 
               $this->processingRateTooLow();
    }

    protected function applyBackpressureMeasures(SystemLoad $load, StreamData $data): void
    {
        // Start with buffering
        if ($this->buffer->canAcceptMore()) {
            $this->buffer->store($data);
            return;
        }

        // Apply throttling if buffer is full
        if ($this->shouldThrottle($load)) {
            $this->throttle->activate([
                'duration' => $this->calculateThrottleDuration($load),
                'rate' => $this->calculateThrottleRate($load)
            ]);
        }

        // Distribute load if possible
        if ($this->loadBalancer->hasAvailableNodes()) {
            $this->loadBalancer->distributeLoad($data);
        }
    }
}

class ThrottleController
{
    protected bool $active = false;
    protected array $config;
    protected array $rates = [];

    public function activate(array $config): void
    {
        $this->active = true;
        $this->config = $config;
        
        // Calculate throttle rates for different priorities
        $this->calculateRates();
        
        // Notify system components
        event(new ThrottleActivatedEvent($this->config));
    }

    public function deactivate(): void
    {
        $this->active = false;
        event(new ThrottleDeactivatedEvent());
    }

    public function shouldThrottle(StreamData $data): bool
    {
        if (!$this->active) {
            return false;
        }

        $rate = $this->getRateForPriority($data->getPriority());
        return $this->isExceedingRate($rate);
    }

    protected function calculateRates(): void
    {
        $baseRate = $this->config['rate'];
        
        $this->rates = [
            'high' => $baseRate,
            'medium' => $baseRate * 0.6,
            'low' => $baseRate * 0.3
        ];
    }
}

class BufferManager
{
    protected CircularBuffer $buffer;
    protected int $capacity;
    protected float $highWatermark = 0.8;
    protected array $overflowHandlers = [];

    public function store(StreamData $data): void
    {
        if ($this->isNearCapacity()) {
            $this->handleNearCapacity();
        }

        if ($this->buffer->hasSpace()) {
            $this->buffer->add($data);
        } else {
            $this->handleOverflow($data);
        }
    }

    public function isNearCapacity(): bool
    {
        return $this->buffer->size() / $this->capacity >= $this->highWatermark;
    }

    public function canAcceptMore(): bool
    {
        return $this->buffer->size() < $this->capacity;
    }

    protected function handleNearCapacity(): void
    {
        // Trigger buffer rotation
        $this->rotateBuffer();

        // Notify monitoring
        event(new BufferNearCapacityEvent([
            'current_size' => $this->buffer->size(),
            'capacity' => $this->capacity,
            'utilization' => $this->buffer->size() / $this->capacity
        ]));
    }

    protected function handleOverflow(StreamData $data): void
    {
        foreach ($this->overflowHandlers as $handler) {
            if ($handler->canHandle($data)) {
                $handler->handle($data);
                return;
            }
        }

        // If no handler could process the data, log and drop
        logger()->warning('Dropping data due to buffer overflow', [
            'data_type' => get_class($data),
            'priority' => $data->getPriority()
        ]);
    }
}

class LoadBalancer
{
    protected array $nodes = [];
    protected NodeHealthChecker $healthChecker;
    protected LoadDistributionStrategy $strategy;

    public function distributeLoad(StreamData $data): void
    {
        // Get available nodes
        $availableNodes = $this->getAvailableNodes();
        
        if (empty($availableNodes)) {
            throw new NoAvailableNodesException();
        }

        // Select optimal node
        $selectedNode = $this->strategy->selectNode(
            $availableNodes,
            $data
        );

        try {
            // Forward data to selected node
            $selectedNode->process($data);
            
            // Update node metrics
            $this->updateNodeMetrics($selectedNode, $data);
            
        } catch (NodeProcessingException $e) {
            $this->handleNodeFailure($selectedNode, $e);
            throw $e;
        }
    }

    public function hasAvailableNodes(): bool
    {
        return !empty($this->getAvailableNodes());
    }

    protected function getAvailableNodes(): array
    {
        return array_filter($this->nodes, function($node) {
            return $this->healthChecker->isHealthy($node) &&
                   $node->canAcceptMore();
        });
    }

    protected function handleNodeFailure(Node $node, NodeProcessingException $e): void
    {
        // Mark node as unhealthy
        $this->healthChecker->markUnhealthy($node);
        
        // Notify monitoring
        event(new NodeFailureEvent($node, $e));
        
        // Trigger node recovery process
        $this->initiateNodeRecovery($node);
    }
}

class SystemLoad
{
    protected array $metrics;
    protected array $thresholds;
    
    public function exceedsThreshold(): bool
    {
        foreach ($this->metrics as $metric => $value) {
            if ($value > ($this->thresholds[$metric] ?? PHP_FLOAT_MAX)) {
                return true;
            }
        }
        return false;
    }

    public function getUtilization(): float
    {
        $utilizationMetrics = [
            'cpu' => $this->metrics['cpu_usage'] ?? 0,
            'memory' => $this->metrics['memory_usage'] ?? 0,
            'io' => $this->metrics['io_usage'] ?? 0
        ];

        return array_sum($utilizationMetrics) / count($utilizationMetrics);
    }
}
```
