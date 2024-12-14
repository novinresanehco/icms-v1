```php
namespace App\Core\Media\Analytics\Streaming;

class StreamingAnalysisEngine
{
    protected StreamProcessor $processor;
    protected WindowManager $windowManager;
    protected AlertDispatcher $alertDispatcher;
    protected MetricsBuffer $buffer;

    public function __construct(
        StreamProcessor $processor,
        WindowManager $windowManager,
        AlertDispatcher $alertDispatcher,
        MetricsBuffer $buffer
    ) {
        $this->processor = $processor;
        $this->windowManager = $windowManager;
        $this->alertDispatcher = $alertDispatcher;
        $this->buffer = $buffer;
    }

    public function processMetricStream(MetricStream $stream): void
    {
        $this->processor->start($stream, function($metric) {
            // Process each metric in real-time
            $windows = $this->windowManager->getCurrentWindows();
            
            foreach ($windows as $window) {
                $window->addMetric($metric);
                
                if ($window->isReady()) {
                    $this->analyzeWindow($window);
                }
            }

            // Update buffer
            $this->buffer->add($metric);
            
            // Check for immediate anomalies
            $this->checkImmediateAnomalies($metric);
        });
    }

    protected function analyzeWindow(Window $window): void
    {
        $analysis = new WindowAnalysis([
            'statistical' => $this->analyzeStatistics($window),
            'patterns' => $this->analyzePatterns($window),
            'anomalies' => $this->detectAnomalies($window)
        ]);

        if ($analysis->hasSignificantFindings()) {
            $this->alertDispatcher->dispatch(
                new AnalysisAlert($analysis)
            );
        }
    }

    protected function checkImmediateAnomalies(Metric $metric): void
    {
        $recentMetrics = $this->buffer->getRecent();
        
        $immediateAnalysis = new ImmediateAnalysis([
            'current' => $metric,
            'recent' => $recentMetrics,
            'thresholds' => $this->getThresholds($metric->getName())
        ]);

        if ($immediateAnalysis->hasAnomalies()) {
            $this->alertDispatcher->dispatch(
                new ImmediateAlert($immediateAnalysis)
            );
        }
    }
}

class WindowManager
{
    protected array $windows = [];
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->initializeWindows();
    }

    protected function initializeWindows(): void
    {
        // Create sliding windows of different sizes
        $this->windows = [
            new SlidingWindow([
                'size' => 60,  // 1 minute
                'slide' => 10  // 10 seconds
            ]),
            new SlidingWindow([
                'size' => 300,  // 5 minutes
                'slide' => 60   // 1 minute
            ]),
            new TumblingWindow([
                'size' => 3600  // 1 hour
            ])
        ];
    }

    public function getCurrentWindows(): array
    {
        // Return only active windows
        return array_filter($this->windows, function($window) {
            return $window->isActive();
        });
    }
}

class SlidingWindow extends Window
{
    protected int $slideInterval;
    protected int $lastSlide;

    public function addMetric(Metric $metric): void
    {
        parent::addMetric($metric);
        
        $this->checkSlide($metric->getTimestamp());
    }

    protected function checkSlide(int $timestamp): void
    {
        if ($timestamp - $this->lastSlide >= $this->slideInterval) {
            $this->slide();
            $this->lastSlide = $timestamp;
        }
    }

    protected function slide(): void
    {
        // Remove metrics outside the window
        $cutoff = time() - $this->size;
        $this->metrics = array_filter(
            $this->metrics,
            fn($m) => $m->getTimestamp() > $cutoff
        );
    }
}

class MetricsBuffer
{
    protected array $buffer = [];
    protected int $maxSize;
    protected array $indexes = [];

    public function add(Metric $metric): void
    {
        // Add to main buffer
        $this->buffer[] = $metric;
        
        // Update indexes
        $this->updateIndexes($metric);
        
        // Maintain buffer size
        $this->maintainSize();
    }

    public function getRecent(int $count = 100): array
    {
        return array_slice($this->buffer, -$count);
    }

    public function getByMetricName(string $name, int $count = 100): array
    {
        return array_slice(
            $this->indexes[$name] ?? [],
            -$count
        );
    }

    protected function updateIndexes(Metric $metric): void
    {
        $name = $metric->getName();
        
        if (!isset($this->indexes[$name])) {
            $this->indexes[$name] = [];
        }
        
        $this->indexes[$name][] = $metric;
    }
}

class StreamProcessor
{
    protected bool $running = false;
    protected array $handlers = [];

    public function start(MetricStream $stream, callable $handler): void
    {
        $this->running = true;
        $this->handlers[] = $handler;

        while ($this->running && ($metric = $stream->next())) {
            $this->processMetric($metric);
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    protected function processMetric(Metric $metric): void
    {
        foreach ($this->handlers as $handler) {
            try {
                $handler($metric);
            } catch (\Exception $e) {
                $this->handleProcessingError($e, $metric);
            }
        }
    }

    protected function handleProcessingError(\Exception $e, Metric $metric): void
    {
        // Log error
        logger()->error('Metric processing error', [
            'metric' => $metric->toArray(),
            'error' => $e->getMessage()
        ]);

        // Notify monitoring
        event(new MetricProcessingFailedEvent($metric, $e));
    }
}

class WindowAnalysis
{
    protected array $statistics;
    protected array $patterns;
    protected array $anomalies;
    protected float $significanceThreshold;

    public function hasSignificantFindings(): bool
    {
        return 
            $this->hasSignificantStatistics() ||
            $this->hasSignificantPatterns() ||
            $this->hasSignificantAnomalies();
    }

    protected function hasSignificantStatistics(): bool
    {
        foreach ($this->statistics as $stat) {
            if ($stat['significance'] > $this->significanceThreshold) {
                return true;
            }
        }
        return false;
    }

    protected function hasSignificantPatterns(): bool
    {
        foreach ($this->patterns as $pattern) {
            if ($pattern['confidence'] > $this->significanceThreshold) {
                return true;
            }
        }
        return false;
    }
}

class AlertDispatcher
{
    protected NotificationService $notifier;
    protected AlertRepository $repository;
    protected AlertCorrelator $correlator;

    public function dispatch(Alert $alert): void
    {
        // Store alert
        $this->repository->store($alert);
        
        // Check for correlations
        $correlations = $this->correlator->findCorrelations($alert);
        
        if ($correlations) {
            $alert->addCorrelations($correlations);
        }

        // Determine notification channels
        $channels = $this->determineChannels($alert);
        
        // Send notifications
        foreach ($channels as $channel) {
            $this->notifier->send($alert, $channel);
        }
    }

    protected function determineChannels(Alert $alert): array
    {
        $channels = [];
        
        // Add channels based on severity
        if ($alert->getSeverity() === 'critical') {
            $channels[] = 'sms';
            $channels[] = 'slack';
        }
        
        $channels[] = 'email';
        
        return $channels;
    }
}
```
