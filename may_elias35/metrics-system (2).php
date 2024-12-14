<?php

namespace App\Core\Metrics\Models;

class Metric extends Model
{
    protected $fillable = [
        'name',
        'type',
        'value',
        'tags',
        'metadata',
        'timestamp'
    ];

    protected $casts = [
        'value' => 'float',
        'tags' => 'array',
        'metadata' => 'array',
        'timestamp' => 'datetime'
    ];
}

class MetricAggregate extends Model
{
    protected $fillable = [
        'name',
        'type',
        'value',
        'period',
        'period_start',
        'period_end',
        'tags',
        'metadata'
    ];

    protected $casts = [
        'value' => 'float',
        'tags' => 'array',
        'metadata' => 'array',
        'period_start' => 'datetime',
        'period_end' => 'datetime'
    ];
}

namespace App\Core\Metrics\Services;

class MetricsManager
{
    private MetricCollector $collector;
    private MetricAggregator $aggregator;
    private MetricStorage $storage;
    private AlertManager $alertManager;

    public function track(string $name, $value, array $tags = [], array $metadata = []): void
    {
        $metric = $this->collector->collect($name, $value, $tags, $metadata);
        $this->storage->store($metric);
        $this->checkAlerts($metric);
    }

    public function aggregate(string $period = '1hour'): void
    {
        $metrics = $this->storage->getForPeriod($period);
        $aggregates = $this->aggregator->aggregate($metrics, $period);
        $this->storage->storeAggregates($aggregates);
    }

    public function query(MetricQuery $query): array
    {
        return $this->storage->query($query);
    }

    private function checkAlerts(Metric $metric): void
    {
        $this->alertManager->check($metric);
    }
}

class MetricCollector
{
    public function collect(string $name, $value, array $tags = [], array $metadata = []): Metric
    {
        return new Metric([
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'metadata' => $metadata,
            'timestamp' => now()
        ]);
    }
}

class MetricAggregator
{
    public function aggregate(Collection $metrics, string $period): Collection
    {
        $groups = $metrics->groupBy(function ($metric) {
            return $metric->name . ':' . json_encode($metric->tags);
        });

        return $groups->map(function ($group) use ($period) {
            return new MetricAggregate([
                'name' => $group->first()->name,
                'type' => $this->determineAggregationType($group),
                'value' => $this->calculateAggregateValue($group),
                'period' => $period,
                'period_start' => $group->min('timestamp'),
                'period_end' => $group->max('timestamp'),
                'tags' => $group->first()->tags,
                'metadata' => $this->mergeMetadata($group)
            ]);
        });
    }

    private function determineAggregationType(Collection $metrics): string
    {
        return 'average';
    }

    private function calculateAggregateValue(Collection $metrics): float
    {
        return $metrics->avg('value');
    }

    private function mergeMetadata(Collection $metrics): array
    {
        return $metrics->pluck('metadata')->reduce(function ($carry, $item) {
            return array_merge($carry ?? [], $item);
        }, []);
    }
}

class MetricStorage
{
    private Repository $repository;

    public function __construct(Repository $repository)
    {
        $this->repository = $repository;
    }

    public function store(Metric $metric): void
    {
        $this->repository->storeMetric($metric);
    }

    public function storeAggregates(Collection $aggregates): void
    {
        $this->repository->storeAggregates($aggregates);
    }

    public function query(MetricQuery $query): array
    {
        return $this->repository->query($query);
    }

    public function getForPeriod(string $period): Collection
    {
        $start = $this->getPeriodStart($period);
        return $this->repository->getMetricsSince($start);
    }

    private function getPeriodStart(string $period): Carbon
    {
        return match($period) {
            '1hour' => now()->subHour(),
            '1day' => now()->subDay(),
            '1week' => now()->subWeek(),
            default => now()->subHour()
        };
    }
}

class AlertManager
{
    private Collection $rules;
    private NotificationManager $notifications;

    public function check(Metric $metric): void
    {
        $this->rules
            ->filter(fn($rule) => $rule->appliesTo($metric))
            ->each(fn($rule) => $this->evaluateRule($rule, $metric));
    }

    private function evaluateRule(AlertRule $rule, Metric $metric): void
    {
        if ($rule->isTriggered($metric)) {
            $this->notifications->send(
                $rule->getNotification($metric)
            );
        }
    }
}

namespace App\Core\Metrics\Http\Controllers;

class MetricsController extends Controller
{
    private MetricsManager $metrics;

    public function query(Request $request): JsonResponse
    {
        $query = new MetricQuery(
            $request->input('name'),
            $request->input('period', '1hour'),
            $request->input('tags', [])
        );

        $results = $this->metrics->query($query);

        return response()->json($results);
    }

    public function track(Request $request): JsonResponse
    {
        $this->metrics->track(
            $request->input('name'),
            $request->input('value'),
            $request->input('tags', []),
            $request->input('metadata', [])
        );

        return response()->json(['message' => 'Metric tracked successfully']);
    }
}

namespace App\Core\Metrics\Console;

class AggregateMetricsCommand extends Command
{
    protected $signature = 'metrics:aggregate {period=1hour}';
    
    public function handle(MetricsManager $metrics): void
    {
        $this->info('Aggregating metrics...');
        $metrics->aggregate($this->argument('period'));
        $this->info('Metrics aggregated successfully');
    }
}
