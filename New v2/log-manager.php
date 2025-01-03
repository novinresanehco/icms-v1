<?php

namespace App\Core\Logging;

class LogManager implements LogInterface
{
    private LogStorage $storage;
    private LogProcessor $processor;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        LogStorage $storage,
        LogProcessor $processor,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->storage = $storage;
        $this->processor = $processor;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $entry = $this->createLogEntry($level, $message, $context);
        $processed = $this->processor->process($entry);

        try {
            $this->storage->store($processed);
            $this->recordMetrics($level, $processed);
            $this->handleCriticalLog($level, $processed);
        } catch (\Throwable $e) {
            $this->handleLogFailure($e, $processed);
        }
    }

    protected function createLogEntry(string $level, string $message, array $context): array
    {
        return [
            'id' => $this->generateLogId(),
            'timestamp' => microtime(true),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
            'request_id' => $this->getRequestId(),
            'user_id' => $this->getUserId(),
            'environment' => $this->config['environment'],
            'system_state' => $this->captureSystemState()
        ];
    }

    protected function shouldLog(string $level): bool
    {
        return $this->config['log_levels'][$level] ?? true;
    }

    protected function recordMetrics(string $level, array $entry): void
    {
        $this->metrics->increment("log.{$level}");
        
        if ($this->isCriticalLevel($level)) {
            $this->metrics->increment('log.critical_total');
            $this->metrics->gauge('log.last_critical_timestamp', $entry['timestamp']);
        }
    }

    protected function handleCriticalLog(string $level, array $entry): void
    {
        if ($this->isCriticalLevel($level)) {
            $this->notifyCriticalLog($entry);
            $this->archiveCriticalLog($entry);
        }
    }

    protected function handleLogFailure(\Throwable $e, array $entry): void
    {
        try {
            $this->storage->storeEmergency([
                'id' => $this->generateLogId(),
                'timestamp' => microtime(true),
                'level' => LogLevel::EMERGENCY,
                'message' => 'Failed to store log entry',
                'context' => [
                    'original_entry' => $entry,
                    'error' => [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]
                ]
            ]);
        } catch (\Throwable $inner) {
            // Last resort: write to error log
            error_log(sprintf(
                'Critical logging failure: %s. Original entry: %s',
                $inner->getMessage(),
                json_encode($entry)
            ));
        }
    }

    protected function notifyCriticalLog(array $entry): void
    {
        if (isset($this->config['critical_notification_handler'])) {
            try {
                ($this->config['critical_notification_handler'])($entry);
            } catch (\Throwable $e) {
                error_log("Failed to send critical log notification: {$e->getMessage()}");
            }
        }
    }

    protected function archiveCriticalLog(array $entry): void
    {
        if (isset($this->config['critical_archive_handler'])) {
            try {
                ($this->config['critical_archive_handler'])($entry);
            } catch (\Throwable $e) {
                error_log("Failed to archive critical log: {$e->getMessage()}");
            }
        }
    }

    protected function generateLogId(): string
    {
        return uniqid('log_', true);
    }

    protected function getRequestId(): ?string
    {
        return request()->id ?? null;
    }

    protected function getUserId(): ?string
    {
        return auth()->id() ?? null;
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg()[0],
            'disk_free_space' => disk_free_space('/'),
            'uptime' => $this->getSystemUptime()
        ];
    }

    protected function getSystemUptime(): float
    {
        return microtime(true) - LARAVEL_START;
    }

    protected function isCriticalLevel(string $level): bool
    {
        return in_array($level, [
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL
        ]);
    }
}

interface LogInterface
{
    public function emergency(string $message, array $context = []): void;
    public function alert(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;