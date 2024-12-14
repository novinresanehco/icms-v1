namespace App\Core\Audit;

class AuditManager implements AuditInterface 
{
    private SecurityManager $security;
    private LogStore $store;
    private MetricsCollector $metrics;
    private EventDispatcher $events;
    private array $config;

    public function __construct(
        SecurityManager $security,
        LogStore $store,
        MetricsCollector $metrics,
        EventDispatcher $events,
        array $config
    ) {
        $this->security = $security;
        $this->store = $store;
        $this->metrics = $metrics;
        $this->events = $events;
        $this->config = $config;
    }

    public function logSecurityEvent(SecurityEvent $event): void 
    {
        $this->security->executeCriticalOperation(new class($event, $this->store, $this->events) implements CriticalOperation {
            private SecurityEvent $event;
            private LogStore $store;
            private EventDispatcher $events;

            public function __construct(SecurityEvent $event, LogStore $store, EventDispatcher $events) 
            {
                $this->event = $event;
                $this->store = $store;
                $this->events = $events;
            }

            public function execute(): OperationResult 
            {
                $logEntry = new LogEntry([
                    'type' => 'security',
                    'severity' => $this->event->getSeverity(),
                    'event_type' => $this->event->getType(),
                    'user_id' => $this->event->getUserId(),
                    'ip_address' => $this->event->getIpAddress(),
                    'data' => $this->event->getData(),
                    'timestamp' => now(),
                    'hash' => $this->generateHash()
                ]);

                $this->store->store($logEntry);
                $this->events->dispatch('security.event.logged', $logEntry);

                if ($this->event->isHighSeverity()) {
                    $this->events->dispatch('security.alert', $logEntry);
                }

                return new OperationResult($logEntry);
            }

            private function generateHash(): string 
            {
                return hash_hmac('sha256', serialize([
                    $this->event->getType(),
                    $this->event->getUserId(),
                    $this->event->getData(),
                    now()->timestamp
                ]), config('app.key'));
            }

            public function getValidationRules(): array 
            {
                return [
                    'event_type' => 'required|string',
                    'user_id' => 'required|integer',
                    'ip_address' => 'required|ip'
                ];
            }

            public function getData(): array 
            {
                return [
                    'event_type' => $this->event->getType(),
                    'severity' => $this->event->getSeverity()
                ];
            }

            public function getRequiredPermissions(): array 
            {
                return ['audit.write'];
            }

            public function getRateLimitKey(): string 
            {
                return "audit:security:{$this->event->getType()}";
            }
        });
    }

    public function logPerformanceMetric(PerformanceMetric $metric): void 
    {
        $this->security->executeCriticalOperation(new class($metric, $this->store, $this->metrics) implements CriticalOperation {
            private PerformanceMetric $metric;
            private LogStore $store;
            private MetricsCollector $metrics;

            public function __construct(PerformanceMetric $metric, LogStore $store, MetricsCollector $metrics) 
            {
                $this->metric = $metric;
                $this->store = $store;
                $this->metrics = $metrics;
            }

            public function execute(): OperationResult 
            {
                $logEntry = new LogEntry([
                    'type' => 'performance',
                    'metric_type' => $this->metric->getType(),
                    'value' => $this->metric->getValue(),
                    'context' => $this->metric->getContext(),
                    'timestamp' => now()
                ]);

                $this->store->store($logEntry);
                $this->metrics->record($this->metric);

                return new OperationResult($logEntry);
            }

            public function getValidationRules(): array 
            {
                return [
                    'metric_type' => 'required|string',
                    'value' => 'required|numeric'
                ];
            }

            public function getData(): array 
            {
                return [
                    'metric_type' => $this->metric->getType(),
                    'value' => $this->metric->getValue()
                ];
            }

            public function getRequiredPermissions(): array 
            {
                return ['audit.metrics'];
            }

            public function getRateLimitKey(): string 
            {
                return "audit:performance:{$this->metric->getType()}";
            }
        });
    }

    public function query(AuditQuery $query): Collection 
    {
        return $this->security->executeCriticalOperation(new class($query, $this->store) implements CriticalOperation {
            private AuditQuery $query;
            private LogStore $store;

            public function __construct(AuditQuery $query, LogStore $store) 
            {
                $this->query = $query;
                $this->store = $store;
            }

            public function execute(): OperationResult 
            {
                $results = $this->store->query($this->query);
                return new OperationResult($results);
            }

            public function getValidationRules(): array 
            {
                return [
                    'start_date' => 'required|date',
                    'end_date' => 'required|date|after:start_date'
                ];
            }

            public function getData(): array 
            {
                return [
                    'query_type' => $this->query->getType(),
                    'date_range' => [
                        $this->query->getStartDate(),
                        $this->query->getEndDate()
                    ]
                ];
            }

            public function getRequiredPermissions(): array 
            {
                return ['audit.query'];
            }

            public function getRateLimitKey(): string 
            {
                return 'audit:query';
            }
        });
    }

    public function verifyIntegrity(DateTimeInterface $startDate, DateTimeInterface $endDate): bool 
    {
        return $this->security->executeCriticalOperation(new class($startDate, $endDate, $this->store) implements CriticalOperation {
            private DateTimeInterface $startDate;
            private DateTimeInterface $endDate;
            private LogStore $store;

            public function __construct(DateTimeInterface $startDate, DateTimeInterface $endDate, LogStore $store) 
            {
                $this->startDate = $startDate;
                $this->endDate = $endDate;
                $this->store = $store;
            }

            public function execute(): OperationResult 
            {
                $verified = $this->store->verifyChainIntegrity(
                    $this->startDate,
                    $this->endDate
                );
                return new OperationResult($verified);
            }

            public function getValidationRules(): array 
            {
                return [
                    'start_date' => 'required|date',
                    'end_date' => 'required|date|after:start_date'
                ];
            }

            public function getData(): array 
            {
                return [
                    'start_date' => $this->startDate->format('Y-m-d H:i:s'),
                    'end_date' => $this->endDate->format('Y-m-d H:i:s')
                ];
            }

            public function getRequiredPermissions(): array 
            {
                return ['audit.verify'];
            }

            public function getRateLimitKey(): string 
            {
                return 'audit:verify';
            }
        });
    }
}
