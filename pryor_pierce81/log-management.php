<?php

namespace App\Core\Monitoring\Logging;

class LogManager
{
    private HandlerRegistry $handlers;
    private ProcessorChain $processors;
    private ContextBuilder $contextBuilder;
    private LogStorage $storage;
    private LogConfig $config;

    public function log(string $level, string $message, array $context = []): void
    {
        $logEntry = new LogEntry(
            $level,
            $message,
            $this->contextBuilder->build($context),
            microtime(true)
        );

        $processedEntry = $this->processors->process($logEntry);

        foreach ($this->handlers->getHandlersForLevel($level) as $handler) {
            try {
                $handler->handle($processedEntry);
            } catch (\Exception $e) {
                // Fallback logging
                $this->handleFailure($handler, $e, $processedEntry);
            }
        }

        $this->storage->store($processedEntry);
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
}

class FileLogHandler implements LogHandler
{
    private string $filepath;
    private LogFormatter $formatter;
    private int $maxFileSize;
    private int $maxFiles;

    public function handle(LogEntry $entry): void
    {
        $formatted = $this->formatter->format($entry);
        
        if ($this->shouldRotate()) {
            $this->rotate();
        }

        file_put_contents(
            $this->filepath,
            $formatted . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    private function shouldRotate(): bool
    {
        return file_exists($this->filepath) &&
               filesize($this->filepath) >= $this->maxFileSize;
    }

    private function rotate(): void
    {
        for ($i = $this->maxFiles - 1; $i >= 0; $i--) {
            $current = $this->getRotatedFilename($i);
            $next = $this->getRotatedFilename($i + 1);

            if (file_exists($current)) {
                rename($current, $next);
            }
        }
    }

    private function getRotatedFilename(int $index): string
    {
        return $index === 0 ? $this->filepath : "{$this->filepath}.{$index}";
    }
}

class DatabaseLogHandler implements LogHandler
{
    private \PDO $db;
    private string $table;
    private QueryBuilder $queryBuilder;
    private int $batchSize;
    private array $buffer = [];

    public function handle(LogEntry $entry): void
    {
        $this->buffer[] = $entry;

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $query = $this->queryBuilder->buildBatchInsert($this->table, $this->buffer);
        $stmt = $this->db->prepare($query);
        $stmt->execute();

        $this->buffer = [];
    }

    public function __destruct()
    {
        $this->flush();
    }
}

class ElasticsearchLogHandler implements LogHandler
{
    private ElasticsearchClient $client;
    private string $index;
    private DocumentFormatter $formatter;
    private BulkProcessor $bulkProcessor;

    public function handle(LogEntry $entry): void
    {
        $document = $this->formatter->format($entry);
        
        $this->bulkProcessor->add([
            'index' => [
                '_index' => $this->getIndexName(),
                '_type' => '_doc'
            ]
        ], $document);
    }

    private function getIndexName(): string
    {
        return $this->index . '-' . date('Y.m.d');
    }
}

class LogEntry
{
    private string $level;
    private string $message;
    private array $context;
    private float $timestamp;
    private ?string $traceId;
    private ?string $spanId;

    public function __construct(
        string $level,
        string $message,
        array $context = [],
        ?float $timestamp = null,
        ?string $traceId = null,
        ?string $spanId = null
    ) {
        $this->level = $level;
        $this->message = $message;
        $this->context = $context;
        $this->timestamp = $timestamp ?? microtime(true);
        $this->traceId = $traceId;
        $this->spanId = $spanId;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function getSpanId(): ?string
    {
        return $this->spanId;
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'message' => $this->message,
            'context' => $this->context,
            'timestamp' => $this->timestamp,
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId
        ];
    }
}

class ProcessorChain
{
    private array $processors = [];

    public function addProcessor(LogProcessor $processor): void
    {
        $this->processors[] = $processor;
    }

    public function process(LogEntry $entry): LogEntry
    {
        $processedEntry = $entry;

        foreach ($this->processors as $processor) {
            $processedEntry = $processor->process($processedEntry);
        }

        return $processedEntry;
    }
}

interface LogProcessor
{
    public function process(LogEntry $entry): LogEntry;
}

class TraceProcessor implements LogProcessor
{
    private TraceContext $traceContext;

    public function process(LogEntry $entry): LogEntry
    {
        $context = $entry->getContext();
        $trace = $this->traceContext->getCurrentTrace();

        return new LogEntry(
            $entry->getLevel(),
            $entry->getMessage(),
            array_merge($context, [
                'trace_id' => $trace->getId(),
                'span_id' => $trace->getCurrentSpan()->getId()
            ]),
            $entry->getTimestamp(),
            $trace->getId(),
            $trace->getCurrentSpan()->getId()
        );
    }
}
