<?php

namespace App\Core\Logging\Contracts;

interface LoggerInterface
{
    public function emergency(string $message, array $context = []): void;
    public function alert(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function notice(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function log(string $level, string $message, array $context = []): void;
}

namespace App\Core\Logging\Services;

class LogManager implements LoggerInterface
{
    protected array $channels = [];
    protected array $processors = [];
    protected array $handlers = [];
    protected Config $config;
    protected ContextBuilder $contextBuilder;

    public function __construct(Config $config, ContextBuilder $contextBuilder)
    {
        $this->config = $config;
        $this->contextBuilder = $contextBuilder;
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

    public function log(string $level, string $message, array $context = []): void
    {
        $entry = $this->createLogEntry($level, $message, $context);
        $this->writeLog($entry);
    }

    protected function createLogEntry(string $level, string $message, array $context): LogEntry
    {
        // Build base entry
        $entry = new LogEntry([
            'id' => Str::uuid(),
            'timestamp' => now(),
            'level' => $level,
            'message' => $message,
            'context' => $context
        ]);

        // Add system context
        $entry->addContext($this->contextBuilder->build());

        // Process entry
        foreach ($this->processors as $processor) {
            $entry = $processor->process($entry);
        }

        return $entry;
    }

    protected function writeLog(LogEntry $entry): void
    {
        // Get configured channels for log level
        $channels = $this->getChannelsForLevel($entry->level);

        foreach ($channels as $channel) {
            try {
                $channel->write($entry);
            } catch (\Exception $e) {
                // Handle channel failure
                $this->handleChannelFailure($channel, $e);
            }
        }
    }

    protected function getChannelsForLevel(string $level): array
    {
        $channels = [];
        foreach ($this->channels as $channel) {
            if ($channel->handlesLevel($level)) {
                $channels[] = $channel;
            }
        }
        return $channels;
    }

    protected function handleChannelFailure(LogChannel $channel, \Exception $e): void
    {
        // Try to log to emergency channel
        $emergency = $this->channels['emergency'] ?? null;
        if ($emergency && $emergency !== $channel) {
            $emergency->write(new LogEntry([
                'level' => 'critical',
                'message' => 'Logging channel failed: ' . $channel->getName(),
                'context' => [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            ]));
        }
    }
}

namespace App\Core\Logging\Services;

class ContextBuilder
{
    protected Request $request;
    protected AuthManager $auth;

    public function build(): array
    {
        return [
            'system' => $this->getSystemContext(),
            'request' => $this->getRequestContext(),
            'user' => $this->getUserContext(),
            'environment' => $this->getEnvironmentContext()
        ];
    }

    protected function getSystemContext(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'process_id' => getmypid(),
            'hostname' => gethostname()
        ];
    }

    protected function getRequestContext(): array
    {
        if (!$this->request) {
            return [];
        }

        return [
            'url' => $this->request->fullUrl(),
            'method' => $this->request->method(),
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'session_id' => session()->getId()
        ];
    }

    protected function getUserContext(): array
    {
        if (!$this->auth->check()) {
            return [];
        }

        $user = $this->auth->user();
        return [
            'id' => $user->id,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')
        ];
    }

    protected function getEnvironmentContext(): array
    {
        return [
            'environment' => app()->environment(),
            'php_version' => PHP_VERSION,
            'timezone' => config('app.timezone')
        ];
    }
}

namespace App\Core\Logging\Channels;

class FileChannel implements LogChannel
{
    protected string $path;
    protected bool $daily;
    protected int $maxFiles;
    protected string $permission;

    public function write(LogEntry $entry): void
    {
        $path = $this->getLogPath($entry);
        $content = $this->formatEntry($entry);

        $this->ensureDirectoryExists(dirname($path));

        file_put_contents(
            $path,
            $content . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public function handlesLevel(string $level): bool
    {
        return true;
    }

    protected function getLogPath(LogEntry $entry): string
    {
        if ($this->daily) {
            return $this->path . '/' . $entry->timestamp->format('Y-m-d') . '.log';
        }

        return $this->path . '/laravel.log';
    }

    protected function formatEntry(LogEntry $entry): string
    {
        return sprintf(
            '[%s] %s.%s: %s %s',
            $entry->timestamp->format('Y-m-d H:i:s'),
            config('app.name'),
            $entry->level,
            $entry->message,
            $this->formatContext($entry->context)
        );
    }

    protected function formatContext(array $context): string
    {
        return json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, $this->permission, true);
        }
    }
}

namespace App\Core\Logging\Processors;

class ExceptionProcessor implements LogProcessor
{
    public function process(LogEntry $entry): LogEntry
    {
        if (isset($entry->context['exception']) && $entry->context['exception'] instanceof \Throwable) {
            $exception = $entry->context['exception'];
            $entry->context['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        return $entry;
    }
}

class SqlQueryProcessor implements LogProcessor
{
    public function process(LogEntry $entry): LogEntry
    {
        if (isset($entry->context['query'])) {
            $query = $entry->context['query'];
            $entry->context['query'] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time
            ];
        }

        return $entry;
    }
}

class SensitiveDataProcessor implements LogProcessor
{
    protected array $sensitiveFields = [
        'password',
        'secret',
        'token',
        'authorization',
        'api_key'
    ];

    public function process(LogEntry $entry): LogEntry
    {
        $entry->context = $this->maskSensitiveData($entry->context);
        return $entry;
    }

    protected function maskSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
            } elseif (is_string($value) && $this->isSensitive($key)) {
                $data[$key] = '********';
            }
        }

        return $data;
    }

    protected function isSensitive(string $key): bool
    {
        $key = strtolower($key);
        foreach ($this->sensitiveFields as $field) {
            if (str_contains($key, $field)) {
                return true;
            }
        }
        return false;
    }
}
