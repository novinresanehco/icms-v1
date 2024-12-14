<?php

namespace App\Core\Logging;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\LoggingException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class SystemLogger implements LoggerInterface
{
    private SecurityManagerInterface $security;
    private array $handlers = [];
    private array $processors = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        array $config = []
    ) {
        $this->security = $security;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        try {
            $this->security->validateContext('logging:write');

            $record = $this->createLogRecord($level, $message, $context);
            $record = $this->processRecord($record);

            $this->validateRecord($record);
            $this->writeRecord($record);

        } catch (\Exception $e) {
            $this->handleLoggingFailure($level, $message, $context, $e);
        }
    }

    private function createLogRecord(string $level, string $message, array $context): array
    {
        return [
            'message' => $message,
            'context' => $context,
            'level' => $level,
            'level_name' => strtoupper($level),
            'channel' => $this->config['channel'],
            'datetime' => new \DateTimeImmutable(),
            'extra' => [
                'user_id' => $this->security->getCurrentUser()?->getId(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'request_id' => request()->id()
            ]
        ];
    }

    private function processRecord(array $record): array
    {
        foreach ($this->processors as $processor) {
            $record = $processor($record);
        }

        return $record;
    }

    private function validateRecord(array $record): void
    {
        if (empty($record['message'])) {
            throw new LoggingException('Log message cannot be empty');
        }

        if (!$this->isValidLevel($record['level'])) {
            throw new LoggingException('Invalid log level');
        }

        $this->validateContext($record['context']);
    }

    private function validateContext(array $context): void
    {
        foreach ($context as $key => $value) {
            if (in_array($key, $this->config['sensitive_fields'])) {
                throw new LoggingException('Context contains sensitive data');
            }
        }
    }

    private function writeRecord(array $record): void
    {
        $written = false;

        foreach ($this->handlers as $handler) {
            if ($handler->isHandling($record)) {
                $handler->handle($record);
                $written = true;
            }
        }

        if (!$written && $this->config['require_handler']) {
            throw new LoggingException('No handler processed the log record');
        }
    }

    private function isValidLevel(string $level): bool
    {
        return defined(LogLevel::class . '::' . strtoupper($level));
    }

    private function handleLoggingFailure(
        string $level,
        string $message,
        array $context,
        \Exception $e
    ): void {
        try {
            if (isset($this->handlers['emergency'])) {
                $this->handlers['emergency']->handle([
                    'message' => 'Logging system failure',
                    'context' => [
                        'original_level' => $level,
                        'original_message' => $message,
                        'original_context' => $context,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ],
                    'level' => LogLevel::EMERGENCY,
                    'datetime' => new \DateTimeImmutable()
                ]);
            }
        } catch (\Exception $e) {
            // Silent failure at this point
        }
    }

    private function getDefaultConfig(): array
    {
        return [
            'channel' => 'system',
            'minimum_level' => LogLevel::DEBUG,
            'bubble' => true,
            'require_handler' => true,
            'sensitive_fields' => [
                'password',
                'token',
                'secret',
                'credit_card'
            ],
            'max_message_size' => 65536 // 64KB
        ];
    }
}
