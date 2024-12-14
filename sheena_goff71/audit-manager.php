namespace App\Core\Audit;

class AuditManager implements AuditInterface
{
    private LogRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    private array $config;

    public function logSecurityEvent(SecurityEvent $event): void 
    {
        $this->executeAuditOperation(new LogSecurityEventOperation(
            $event,
            function() use ($event) {
                $this->validateAndStore([
                    'type' => 'security',
                    'severity' => $event->getSeverity(),
                    'event_type' => $event->getType(),
                    'details' => $event->getDetails(),
                    'user_id' => $event->getUserId(),
                    'ip_address' => $event->getIpAddress(),
                    'timestamp' => now(),
                    'metadata' => $this->getSecurityMetadata($event)
                ]);
            }
        ));
    }

    public function logOperationEvent(OperationEvent $event): void 
    {
        $this->executeAuditOperation(new LogOperationEventOperation(
            $event,
            function() use ($event) {
                $this->validateAndStore([
                    'type' => 'operation',
                    'operation' => $event->getOperation(),
                    'status' => $event->getStatus(),
                    'details' => $event->getDetails(),
                    'user_id' => $event->getUserId(),
                    'duration' => $event->getDuration(),
                    'timestamp' => now(),
                    'metadata' => $this->getOperationMetadata($event)
                ]);
            }
        ));
    }

    public function logSystemEvent(SystemEvent $event): void 
    {
        $this->executeAuditOperation(new LogSystemEventOperation(
            $event,
            function() use ($event) {
                $this->validateAndStore([
                    'type' => 'system',
                    'component' => $event->getComponent(),
                    'event_type' => $event->getType(),
                    'details' => $event->getDetails(),
                    'severity' => $event->getSeverity(),
                    'timestamp' => now(),
                    'metadata' => $this->getSystemMetadata($event)
                ]);
            }
        ));
    }

    public function logDataEvent(DataEvent $event): void 
    {
        $this->executeAuditOperation(new LogDataEventOperation(
            $event,
            function() use ($event) {
                $this->validateAndStore([
                    'type' => 'data',
                    'entity_type' => $event->getEntityType(),
                    'entity_id' => $event->getEntityId(),
                    'action' => $event->getAction(),
                    'changes' => $event->getChanges(),
                    'user_id' => $event->getUserId(),
                    'timestamp' => now(),
                    'metadata' => $this->getDataMetadata($event)
                ]);
            }
        ));
    }

    protected function executeAuditOperation(AuditOperation $operation): void 
    {
        $this->security->executeCriticalOperation(
            $operation,
            function() use ($operation) {
                DB::beginTransaction();
                
                try {
                    $operation->execute();
                    
                    if ($this->config['sync_mode'] === 'async') {
                        $this->dispatchToQueue($operation);
                    }
                    
                    DB::commit();
                    
                    $this->monitor->recordAuditSuccess($operation);
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    
                    $this->monitor->recordAuditFailure($operation, $e);
                    
                    if ($this->config['fail_strategy'] === 'throw') {
                        throw new AuditFailureException(
                            'Failed to log audit event',
                            0,
                            $e
                        );
                    }
                }
            }
        );
    }

    protected function validateAndStore(array $data): void 
    {
        $validated = $this->validator->validate($data, [
            'type' => 'required|string',
            'timestamp' => 'required|date',
            'details' => 'required|array',
            'metadata' => 'required|array'
        ]);

        $this->repository->store($validated);

        if ($this->config['retention_days']) {
            $this->cleanupOldRecords();
        }
    }

    protected function getSecurityMetadata(SecurityEvent $event): array 
    {
        return array_merge(
            $event->getMetadata(),
            [
                'environment' => app()->environment(),
                'session_id' => session()->getId(),
                'request_id' => request()->id()
            ]
        );
    }

    protected function getOperationMetadata(OperationEvent $event): array 
    {
        return array_merge(
            $event->getMetadata(),
            [
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'process_id' => getmypid()
            ]
        );
    }

    protected function getSystemMetadata(SystemEvent $event): array 
    {
        return array_merge(
            $event->getMetadata(),
            [
                'server_load' => sys_getloadavg(),
                'disk_usage' => disk_free_space('/'),
                'uptime' => time() - LARAVEL_START
            ]
        );
    }

    protected function getDataMetadata(DataEvent $event): array 
    {
        return array_merge(
            $event->getMetadata(),
            [
                'database' => config('database.default'),
                'table_size' => $this->getTableSize($event->getEntityType()),
                'row_count' => $this->getTableCount($event->getEntityType())
            ]
        );
    }

    protected function cleanupOldRecords(): void 
    {
        $this->repository->deleteOlderThan(
            now()->subDays($this->config['retention_days'])
        );
    }

    protected function dispatchToQueue(AuditOperation $operation): void 
    {
        dispatch(new ProcessAuditEvent($operation))
            ->onQueue($this->config['queue_name']);
    }
}
