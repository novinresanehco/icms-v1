<?php

namespace App\Core\Logging;

use App\Core\Security\SecurityManager;
use App\Core\Exceptions\LoggerException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\{File, Storage};

class LogManager implements LoggerInterface
{
    private SecurityManager $security;
    private LogWriter $writer;
    private LogProcessor $processor;
    private AlertManager $alerts;
    private array $config;

    public function __construct(
        SecurityManager $security,
        LogWriter $writer,
        LogProcessor $processor,
        AlertManager $alerts,
        array $config
    ) {
        $this->security = $security;
        $this->writer = $writer;
        $this->processor = $processor;
        $this->alerts = $alerts;
        $this->config = $config;
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, string $message, array $context = []): void
    {
        $logEntry = $this->processor->processLog(
            $level,
            $message,
            $context
        );

        $this->writer->write($logEntry);

        if ($this->shouldAlert($level)) {
            $this->alerts->alert($logEntry);
        }
    }
}

class LogWriter
{
    private string $path;
    private array $config;

    public function write(LogEntry $entry): void
    {
        $file = $this->getLogFile($entry);

        try {
            if (!File::exists($file)) {
                File::put($file, '');
                File::chmod($file, 0600);
            }

            File::append(
                $file,
                $this->formatEntry($entry) . PHP_EOL
            );

            $this->rotateIfNeeded($file);

        } catch (\Exception $e) {
            throw new LoggerException(
                'Failed to write log entry: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function getLogFile(LogEntry $entry): string
    {
        $filename = date('Y-m-d') . '.' . $entry->getChannel() . '.log';
        return $this->path . '/' . $filename;
    }

    private function formatEntry(LogEntry $entry): string
    {
        return json_encode([
            'timestamp' => $entry->getTimestamp(),
            'level' => $entry->getLevel(),
            'message' => $entry->getMessage(),
            'context' => $entry->getContext(),
            'channel' => $entry->getChannel(),
            'request_id' => $entry->getRequestId(),
            'user_id' => $entry->getUserId()
        ]);
    }

    private function rotateIfNeeded(string $file): void
    {
        $size = File::size($file);

        if ($size > $this->config['max_file_size']) {
            $this->rotateFile($file);
        }

        $this->cleanOldLogs();
    }

    private function rotateFile(string $file): void
    {
        $rotated = $file . '.' . time() . '.gz';
        
        $handle = fopen($file, 'r');
        $gz = gzopen($rotated, 'w9');

        while (!feof($handle)) {
            gzwrite($gz, fread($handle, 8192));
        }

        fclose($handle);
        gzclose($gz);

        File::put($file, '');
    }

    private function cleanOldLogs(): void
    {
        $retention = $this->config['retention_days'];
        $cutoff = strtotime("-{$retention} days");

        foreach (File::glob($this->path . '/*.log*') as $file) {
            $modified = File::lastModified($file);
            
            if ($modified < $cutoff) {
                File::delete($file);
            }
        }
    }
}

class LogProcessor 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $config;
    private ?string $requestId = null;

    public function processLog(string $level, string $message, array $context): LogEntry
    {
        $this->validateLevel($level);
        $this->validateMessage($message);
        $this->validateContext($context);

        return new LogEntry(
            $level,
            $message,
            $this->sanitizeContext($context),
            $this->getChannel(),
            $this->getRequestId(),
            $this->getUserId()
        );
    }

    private function validateLevel(string $level): void
    {
        $validLevels = ['emergency', 'alert', 'critical', 'error', 
                       'warning', 'notice', 'info', 'debug'];

        if (!in_array($level, $validLevels)) {
            throw new LoggerException('Invalid log level');
        }
    }

    private function validateMessage(string $message): void
    {
        if (empty($message)) {
            throw new LoggerException('Empty log message');
        }

        if (strlen($message) > $this->config['max_message_length']) {
            throw new LoggerException('Log message too long');
        }
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new LoggerException('Invalid log context');
        }
    }

    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (in_array($key, $this->config['sensitive_fields'])) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }

        return $sanitized;
    }

    private function sanitizeValue($value): mixed
    {
        if (is_string($value)) {
            return substr($value, 0, $this->config['max_field_length']);
        }

        if (is_array($value)) {
            return array_map([$this, 'sanitizeValue'], $value);
        }

        return $value;
    }

    private function getRequestId(): string
    {
        if ($this->requestId === null) {
            $this->requestId = bin2hex(random_bytes(16));
        }
        return $this->requestId;
    }

    private function getUserId(): ?int
    {
        return $this->security->getCurrentUser()?->getId();
    }

    private function getChannel(): string
    {
        return $this->config['channel'] ?? 'app';
    }
}
