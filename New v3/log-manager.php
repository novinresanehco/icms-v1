<?php

namespace App\Core\Monitoring;

/**
 * Critical Log Management System
 * Handles all system logging with security, performance and diagnostic tracking
 */
class LogManager implements LogManagerInterface
{
    private SecurityManager $security;
    private StorageManager $storage;
    private array $config;
    private array $handlers = [];
    private array $buffer = [];

    public function __construct(
        SecurityManager $security,
        StorageManager $storage,
        array $config
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->config = $config;
        $this->initializeHandlers();
    }

    public function log(string $level, string $message, array $context = []): void
    {
        try {
            // Validate level and context
            $this->validateLogLevel($level);
            $this->validateContext($context);

            // Create log entry
            $entry = $this->createLogEntry($level, $message, $context);

            // Apply security filters
            $entry = $this->security->filterSensitiveData($entry);

            // Process entry
            $this->processLogEntry($entry);

            // Check if immediate flush is needed
            if ($this->shouldFlushBuffer($entry)) {
                $this->flush();
            }

        } catch (\Exception $e) {
            $this->handleLoggingFailure($e, $level, $message, $context);
        }
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
        $this->handleEmergency($message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
        if ($this->isSystemAlert($context)) {
            $this->notifySystemAlert($message, $context);
        }
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
        $this->handleCritical($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
        if ($this->isRecoverableError($context)) {
            $this->attemptErrorRecovery($message, $context);
        }
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
        $this->analyzeWarningPattern($message, $context);
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
        if ($this->isDebugEnabled()) {
            $this->log(LogLevel::DEBUG, $message, $context);
        }
    }

    public function query(array $criteria): array
    {
        try {
            // Validate query criteria
            $this->validateQueryCriteria($criteria);
            
            // Apply security filters
            $criteria = $this->security->filterQueryCriteria($criteria);
            
            // Build query
            $query = $this->buildLogQuery($criteria);
            
            // Execute query with performance tracking
            $startTime = microtime(true);
            $results = $this->executeLogQuery($query);
            $duration = microtime(true) - $startTime;
            
            // Record query metrics
            $this->recordQueryMetrics($criteria, $duration, count($results));
            
            return $results;
            
        } catch (\Exception $e) {
            $this->handleQueryFailure($e, $criteria);
            throw new LogQueryException('Log query failed', 0, $e);
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            // Process buffered entries
            foreach ($this->handlers as $handler) {
                $handler->processBatch($this->buffer);
            }

            // Store to permanent storage
            $this->storage->storeLogs($this->buffer);

            // Clear buffer
            $this->buffer = [];

        } catch (\Exception $e) {
            $this->handleFlushFailure($e);
        }
    }

    private function createLogEntry(string $level, string $message, array $context): array
    {
        return [
            'timestamp' => microtime(true),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
            'process_id' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'request_id' => $this->getRequestId(),
            'user_id' => $this->security->getCurrentUserId(),
            'ip' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'environment' => $this->config['environment']
        ];
    }

    private function processLogEntry(array $entry): void
    {
        // Add to buffer
        $this->buffer[] = $entry;

        // Process through handlers
        foreach ($this->handlers as $handler) {
            if ($handler->shouldHandle($entry)) {
                $handler->handle($entry);
            }
        }

        // Check for patterns
        $this->analyzeLogPatterns($entry);

        // Update metrics
        $this->updateLogMetrics($entry);
    }

    private function shouldFlushBuffer(array $entry): bool
    {
        return $this->isHighPriorityLog($entry) ||
            count($this->buffer) >= $this->config['buffer_size'] ||
            memory_get_usage(true) > $this->config['memory_limit'];
    }

    private function handleEmergency(string $message, array $context): void
    {
        // Notify emergency contacts
        foreach ($this->config['emergency_contacts'] as $contact) {
            $this->notifyContact($contact, $message, $context);
        }

        // Execute emergency procedures
        $this->executeEmergencyProcedures($message, $context);

        // Create system snapshot
        $this->createSystemSnapshot();
    }

    private function handleCritical(string $message, array $context): void
    {
        // Check if system stability is affected
        if ($this->isSystemStabilityAffected($context)) {
            $this->initiateSystemRecovery($context);
        }

        // Notify relevant teams
        $this->notifyTeams($message, $context);

        // Start incident tracking
        $this->trackCriticalIncident($message, $context);
    }

    private function analyzeWarningPattern(string $message, array $context): void
    {
        // Check for repeated warnings
        if ($this->isRepeatedWarning($message)) {
            $this->handleRepeatedWarning($message, $context);
        }

        // Analyze warning trends
        $this->analy