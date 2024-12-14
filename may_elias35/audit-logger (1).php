namespace App\Core\Security;

class AuditLogger implements AuditLoggerInterface
{
    private DatabaseManager $db;
    private QueueManager $queue;
    private SecurityConfig $config;
    private CacheManager $cache;
    
    public function __construct(
        DatabaseManager $db,
        QueueManager $queue,
        SecurityConfig $config,
        CacheManager $cache
    ) {
        $this->db = $db;
        $this->queue = $queue;
        $this->config = $config;
        $this->cache = $cache;
    }

    public function logSecurityEvent(SecurityEvent $event): void
    {
        $eventData = $this->prepareEventData($event);
        
        // Critical events get immediate processing
        if ($event->isCritical()) {
            $this->processCriticalEvent($eventData);
        } else {
            $this->queueEvent($eventData);
        }
        
        // Update security metrics
        $this->updateMetrics($event);
    }

    public function logAccess(AccessEvent $event): void
    {
        $logData = [
            'user_id' => $event->getUserId(),
            'resource' => $event->getResource(),
            'action' => $event->getAction(),
            'ip_address' => $event->getIpAddress(),
            'timestamp' => $event->getTimestamp(),
            'success' => $event->isSuccessful(),
            'failure_reason' => $event->getFailureReason(),
            'request_id' => $event->getRequestId(),
            'session_id' => $event->getSessionId(),
            'metadata' => $event->getMetadata()
        ];

        $this->storeAccessLog($logData);
        $this->detectAnomalies($logData);
    }

    public function logOperation(OperationEvent $event): void
    {
        DB::transaction(function() use ($event) {
            // Store main operation log
            $logId = $this->storeOperationLog([
                'operation_type' => $event->getType(),
                'user_id' => $event->getUserId(),
                'status' => $event->getStatus(),
                'duration' => $event->getDuration(),
                'timestamp' => $event->getTimestamp(),
                'request_id' => $event->getRequestId(),
                'metadata' => $event->getMetadata()
            ]);

            // Store operation details
            $this->storeOperationDetails($logId, $event->getDetails());

            // Track operation metrics
            $this->trackOperationMetrics($event);
        });
    }

    public function startOperation(string $type, array $context = []): string
    {
        $operationId = $this->generateOperationId();
        
        $this->cache->set(
            $this->getOperationKey($operationId),
            [
                'type' => $type,
                'context' => $context,
                'start_time' => microtime(true),
                'status' => 'started'
            ],
            $this->config->getOperationTimeout()
        );
        
        return $operationId;
    }

    public function endOperation(string $operationId, array $result = []): void
    {
        $operationData = $this->cache->get($this->getOperationKey($operationId));
        
        if (!$operationData) {
            throw new InvalidOperationException('Operation not found or expired');
        }
        
        $duration = microtime(true) - $operationData['start_time'];
        
        $this->logOperation(new OperationEvent(
            $operationData['type'],
            'completed',
            $duration,
            array_merge($operationData['context'], $result)
        ));
        
        $this->cache->delete($this->getOperationKey($operationId));
    }

    private function processCriticalEvent(array $eventData): void
    {
        DB::transaction(function() use ($eventData) {
            // Store in primary log
            $this->storeCriticalEvent($eventData);
            
            // Send immediate notifications
            $this->notifyCriticalEvent($eventData);
            
            // Update security state
            $this->updateSecurityState($eventData);
        });
    }

    private function queueEvent(array $eventData): void
    {
        $this->queue->push('security-events', $eventData);
    }

    private function storeAccessLog(array $logData): void
    {
        $this->db->table('access_logs')->insert(
            array_merge($logData, [
                'created_at' => now(),
                'environment' => app()->environment()
            ])
        );
    }

    private function detectAnomalies(array $logData): void
    {
        $detector = $this->config->getAnomalyDetector();
        
        if ($detector->isAnomaly($logData)) {
            $this->logSecurityEvent(new SecurityEvent(
                'anomaly_detected',
                SecurityLevel::WARNING,
                $logData
            ));
        }
    }

    private function trackOperationMetrics(OperationEvent $event): void
    {
        $metrics = [
            'operation_count' => 1,
            'operation_duration' => $event->getDuration(),
            'operation_success' => $event->isSuccess() ? 1 : 0,
            'operation_failure' => $event->isSuccess() ? 0 : 1
        ];

        foreach ($metrics as $metric => $value) {
            $this->updateMetric($event->getType(), $metric, $value);
        }
    }

    private function updateMetric(string $type, string $metric, $value): void
    {
        $key = "metrics:{$type}:{$metric}";
        $this->cache->increment($key, $value);
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    private function getOperationKey(string $operationId): string
    {
        return "operation:{$operationId}";
    }
}
