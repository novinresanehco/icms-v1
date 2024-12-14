namespace App\Core\Monitoring;

class MetricsManager implements MonitoringInterface
{
    private SecurityManager $security;
    private MetricsStore $store;
    private AuditLogger $audit;
    private EventDispatcher $events;
    private AlertManager $alerts;

    public function __construct(
        SecurityManager $security,
        MetricsStore $store,
        AuditLogger $audit,
        EventDispatcher $events,
        AlertManager $alerts
    ) {
        $this->security = $security;
        $this->store = $store;
        $this->audit = $audit;
        $this->events = $events;
        $this->alerts = $alerts;
    }

    public function recordMetric(string $name, $value, array $tags = []): void
    {
        $this->security->executeCriticalOperation(new class($name, $value, $tags, $this->store, $this->alerts) implements CriticalOperation {
            private string $name;
            private mixed $value;
            private array $tags;
            private MetricsStore $store;
            private AlertManager $alerts;

            public function __construct(
                string $name, 
                $value,
                array $tags,
                MetricsStore $store,
                AlertManager $alerts
            ) {
                $this->name = $name;
                $this->value = $value;
                $this->tags = $tags;
                $this->store = $store;
                $this->alerts = $alerts;
            }

            public function execute(): OperationResult
            {
                $metric = new Metric(
                    $this->name,
                    $this->value,
                    $this->tags,
                    microtime(true)
                );

                $this->store->store($metric);
                
                if ($this->shouldAlert($metric)) {
                    $this->alerts->trigger($metric);
                }

                return new OperationResult($metric);
            }

            private function shouldAlert(Metric $metric): bool
            {
                return $metric->exceedsThreshold() || 
                       $metric->isAnomaly() ||
                       $metric->isSecurityRelevant();
            }

            public function getValidationRules(): array
            {
                return [
                    'name' => 'required|string',
                    'tags' => 'array'
                ];
            }

            public function getData(): array
            {
                return [
                    'name' => $this->name,
                    'tags' => $this->tags
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['metrics.write'];
            }

            public function getRateLimitKey(): string
            {
                return "metrics:write:{$this->name}";
            }
        });
    }

    public function queryMetrics(MetricQuery $query): array
    {
        return $this->security->executeCriticalOperation(new class($query, $this->store) implements CriticalOperation {
            private MetricQuery $query;
            private MetricsStore $store;

            public function __construct(MetricQuery $query, MetricsStore $store)
            {
                $this->query = $query;
                $this->store = $store;
            }

            public function execute(): OperationResult
            {
                $metrics = $this->store->query(
                    $this->query->getNames(),
                    $this->query->getTags(),
                    $this->query->getTimeRange(),
                    $this->query->getAggregation()
                );

                return new OperationResult($metrics);
            }

            public function getValidationRules(): array
            {
                return [
                    'names' => 'required|array',
                    'time_range' => 'required|array'
                ];
            }

            public function getData(): array
            {
                return [
                    'names' => $this->query->getNames(),
                    'time_range' => $this->query->getTimeRange()
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['metrics.read'];
            }

            public function getRateLimitKey(): string
            {
                return 'metrics:query';
            }
        });
    }

    public function getAlerts(): array
    {
        return $this->security->executeCriticalOperation(new class($this->alerts) implements CriticalOperation {
            private AlertManager $alerts;

            public function __construct(AlertManager $alerts)
            {
                $this->alerts = $alerts;
            }

            public function execute(): OperationResult
            {
                return new OperationResult($this->alerts->getActive());
            }

            public function getValidationRules(): array
            {
                return [];
            }

            public function getData(): array
            {
                return [];
            }

            public function getRequiredPermissions(): array
            {
                return ['metrics.alerts'];
            }

            public function getRateLimitKey(): string
            {
                return 'metrics:alerts';
            }
        });
    }

    public function configureAlert(string $name, AlertConfig $config): void
    {
        $this->security->executeCriticalOperation(new class($name, $config, $this->alerts) implements CriticalOperation {
            private string $name;
            private AlertConfig $config;
            private AlertManager $alerts;

            public function __construct(
                string $name,
                AlertConfig $config,
                AlertManager $alerts
            ) {
                $this->name = $name;
                $this->config = $config;
                $this->alerts = $alerts;
            }

            public function execute(): OperationResult
            {
                $this->alerts->configureAlert(
                    $this->name,
                    $this->config
                );

                return new OperationResult(true);
            }

            public function getValidationRules(): array
            {
                return [
                    'name' => 'required|string',
                    'threshold' => 'required|numeric'
                ];
            }

            public function getData(): array
            {
                return [
                    'name' => $this->name,
                    'threshold' => $this->config->getThreshold()
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['metrics.configure'];
            }

            public function getRateLimitKey(): string
            {
                return "metrics:configure:{$this->name}";
            }
        });
    }
}
