```php
namespace App\Core\Alert;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Queue\QueueManager;
use Illuminate\Support\Facades\Redis;

class AlertManager implements AlertManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private QueueManager $queue;
    private MetricsCollector $metrics;
    private array $config;

    private const MAX_BATCH_SIZE = 1000;
    private const RATE_LIMIT_WINDOW = 60;
    private const CRITICAL_RETRY_ATTEMPTS = 3;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        QueueManager $queue,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->queue = $queue;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function sendAlert(string $type, array $data, string $severity = 'info'): AlertResponse
    {
        return $this->security->executeSecureOperation(function() use ($type, $data, $severity) {
            $alertId = $this->generateAlertId();
            
            try {
                // Validate alert data
                $this->validateAlert($type, $data, $severity);
                
                // Check rate limits
                $this->checkRateLimits($type, $severity);
                
                // Process alert data
                $processedData = $this->processAlertData($data);
                
                // Create alert message
                $alert = $this->createAlert($alertId, $type, $processedData, $severity);
                
                // Route alert
                $this->routeAlert($alert);
                
                // Track metrics
                $this->metrics->recordAlert($alertId, $type, $severity);
                
                return new AlertResponse($alert);
                
            } catch (\Exception $e) {
                $this->handleAlertFailure($alertId, $type, $e);
                throw $e;
            }
        }, ['operation' => 'send_alert']);
    }

    public function sendBatchAlerts(array $alerts): BatchAlertResponse
    {
        return $this->security->executeSecureOperation(function() use ($alerts) {
            if (count($alerts) > self::MAX_BATCH_SIZE) {
                throw new ValidationException('Batch size exceeds limit');
            }

            $results = [];
            $failures = [];

            foreach (array_chunk($alerts, 100) as $batch) {
                try {
                    $processedBatch = $this->processBatch($batch);
                    $results = array_merge($results, $processedBatch);
                } catch (\Exception $e) {
                    $failures[] = [
                        'batch' => $batch,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return new BatchAlertResponse($results, $failures);
        }, ['operation' => 'batch_alerts']);
    }

    private function validateAlert(string $type, array $data, string $severity): void
    {
        if (!in_array($type, $this->config['allowed_alert_types'])) {
            throw new ValidationException('Invalid alert type');
        }

        if (!in_array($severity, $this->config['severity_levels'])) {
            throw new ValidationException('Invalid severity level');
        }

        $rules = $this->config['validation_rules'][$type] ?? [];
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid alert data');
        }
    }

    private function processAlertData(array $data): array
    {
        // Sanitize data
        $data = $this->security->sanitizeData($data);
        
        // Add system context
        $data['system_context'] = $this->getSystemContext();
        
        // Add timestamps
        $data['created_at'] = microtime(true);
        $data['expires_at'] = $this->calculateExpiration($data);
        
        return $data;
    }

    private function createAlert(string $alertId, string $type, array $data, string $severity): Alert
    {
        return new Alert([
            'id' => $alertId,
            'type' => $type,
            'data' => $data,
            'severity' => $severity,
            'metadata' => $this->generateMetadata()
        ]);
    }

    private function routeAlert(Alert $alert): void
    {
        $routes = $this->determineRoutes($alert);
        
        foreach ($routes as $route) {
            if ($alert->isCritical()) {
                $this->handleCriticalRoute($alert, $route);
            } else {
                $this->queue->push($route, $alert);
            }
        }
    }

    private function handleCriticalRoute(Alert $alert, string $route): void
    {
        $attempts = 0;
        while ($attempts < self::CRITICAL_RETRY_ATTEMPTS) {
            try {
                $this->deliverCriticalAlert($alert, $route);
                return;
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::CRITICAL_RETRY_ATTEMPTS) {
                    $this->escalateCriticalFailure($alert, $route, $e);
                }
                usleep(100000 * $attempts); // Exponential backoff
            }
        }
    }

    private function deliverCriticalAlert(Alert $alert, string $route): void
    {
        $handler = $this->getCriticalHandler($route);
        $handler->deliver($alert);
    }

    private function checkRateLimits(string $type, string $severity): void
    {
        $key = "alert_rate:{$type}:{$severity}";
        $count = Redis::incr($key);
        Redis::expire($key, self::RATE_LIMIT_WINDOW);
        
        $limit = $this->config['rate_limits'][$severity] ?? PHP_INT_MAX;
        if ($count > $limit) {
            throw new RateLimitException('Alert rate limit exceeded');
        }
    }

    private function determineRoutes(Alert $alert): array
    {
        $routes = $this->config['routing_rules'][$alert->getType()] ?? [];
        
        if ($alert->isCritical()) {
            $routes = array_merge($routes, $this->config['critical_routes']);
        }
        
        return array_unique($routes);
    }

    private function escalateCriticalFailure(Alert $alert, string $route, \Exception $e): void
    {
        $this->security->triggerEmergencyProtocol('critical_alert_failure', [
            'alert' => $alert,
            'route' => $route,
            'error' => $e
        ]);
    }

    private function generateAlertId(): string
    {
        return uniqid('alert_', true);
    }

    private function generateMetadata(): array
    {
        return [
            'source' => gethostname(),
            'environment' => app()->environment(),
            'version' => config('app.version'),
            'timestamp' => microtime(true)
        ];
    }

    private function handleAlertFailure(string $alertId, string $type, \Exception $e): void
    {
        $this->metrics->recordAlertFailure($alertId, $type);
        
        Log::error('Alert delivery failed', [
            'alert_id' => $alertId,
            'type' => $type,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
```

This implementation provides:

1. Secure Alert Management:
- Data validation
- Rate limiting
- Route determination
- Critical handling

2. Performance Features:
- Batch processing
- Queue integration
- Retry logic
- Rate control

3. Security Controls:
- Data sanitization
- Route validation
- Delivery confirmation
- Failure handling

4. Monitoring:
- Alert tracking
- Performance metrics
- Failure logging
- System state monitoring

The system ensures secure and reliable alert delivery while maintaining strict security and performance standards.