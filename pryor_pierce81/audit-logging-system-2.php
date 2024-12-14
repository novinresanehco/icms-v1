namespace App\Core\Audit;

class AuditLogger implements AuditInterface 
{
    private LogRepository $repository;
    private EncryptionService $encryption;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private EventDispatcher $events;

    public function __construct(
        LogRepository $repository,
        EncryptionService $encryption,
        CacheManager $cache,
        MetricsCollector $metrics,
        EventDispatcher $events
    ) {
        $this->repository = $repository;
        $this->encryption = $encryption;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->events = $events;
    }

    public function logSecurityEvent(SecurityEvent $event): void
    {
        $logEntry = $this->prepareLogEntry(
            'security',
            $event->getData(),
            $event->getSeverity()
        );

        $this->writeLog($logEntry);
        $this->handleCriticalEvent($event);
    }

    public function logOperationEvent(OperationEvent $event): void 
    {
        $logEntry = $this->prepareLogEntry(
            'operation',
            $event->getData(),
            $event->getType()
        );

        $this->writeLog($logEntry);
        $this->trackPerformance($event);
    }

    public function logAuthEvent(AuthEvent $event): void
    {
        $logEntry = $this->prepareLogEntry(
            'auth',
            $event->getData(),
            $event->getStatus()
        );

        $this->writeLog($logEntry);
        $this->detectAuthAnomaly($event);
    }

    private function prepareLogEntry(
        string $type,
        array $data,
        string $level
    ): array {
        return [
            'type' => $type,
            'data' => $this->encryption->encrypt(json_encode($data)),
            'level' => $level,
            'timestamp' => microtime(true),
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];
    }

    private function writeLog(array $entry): void
    {
        try {
            DB::transaction(function () use ($entry) {
                $this->repository->create($entry);
                $this->cache->tags(['audit_logs'])->flush();
                $this->metrics->increment("audit_logs.{$entry['type']}");
            });

            $this->events->dispatch(
                new LogWrittenEvent($entry)
            );
        } catch (\Exception $e) {
            $this->handleLogFailure($e, $entry);
        }
    }

    private function handleCriticalEvent(SecurityEvent $event): void
    {
        if ($event->isCritical()) {
            $this->events->dispatch(
                new CriticalSecurityEvent($event)
            );

            $this->metrics->increment('security.critical_events');
        }
    }

    private function trackPerformance(OperationEvent $event): void
    {
        $this->metrics->timing(
            "operations.{$event->getType()}",
            $event->getDuration()
        );

        if ($event->isSlowOperation()) {
            $this->events->dispatch(
                new SlowOperationDetectedEvent($event)
            );
        }
    }

    private function detectAuthAnomaly(AuthEvent $event): void
    {
        $key = "auth_attempts:{$event->getUserId()}";
        
        $attempts = $this->cache->remember(
            $key,
            300,
            fn() => $this->repository->countRecentAuthAttempts($event->getUserId())
        );

        if ($attempts > config('auth.max_attempts')) {
            $this->events->dispatch(
                new AuthAnomalyDetectedEvent($event)
            );
        }
    }

    private function handleLogFailure(\Exception $e, array $entry): void
    {
        $this->metrics->increment('audit_logs.failures');
        
        $fallbackPath = storage_path('logs/audit_fallback.log');
        file_put_contents(
            $fallbackPath,
            json_encode($entry) . PHP_EOL,
            FILE_APPEND
        );

        $this->events->dispatch(
            new LogFailureEvent($e, $entry)
        );
    }

    public function getLogsByType(string $type, array $filters = []): Collection
    {
        $cacheKey = "audit_logs:{$type}:" . md5(serialize($filters));
        
        return $this->cache->remember($cacheKey, 300, function () use ($type, $filters) {
            return $this->repository->getByType($type, $filters);
        });
    }

    public function purgeOldLogs(int $daysToKeep = 90): int
    {
        $purged = $this->repository->purgeOldLogs($daysToKeep);
        $this->cache->tags(['audit_logs'])->flush();
        $this->metrics->gauge('audit_logs.total', $this->repository->count());
        return $purged;
    }
}
