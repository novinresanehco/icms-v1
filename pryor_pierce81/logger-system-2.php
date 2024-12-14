<?php

namespace App\Core\Logging;

class LogManager
{
    private array $channels = [];
    private array $processors = [];
    private array $formatters = [];
    private array $handlers = [];

    public function channel(string $name): LogChannel
    {
        if (!isset($this->channels[$name])) {
            $this->channels[$name] = $this->createChannel($name);
        }
        return $this->channels[$name];
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->channel('default')->log($level, $message, $context);
    }

    public function addProcessor(LogProcessor $processor): void
    {
        $this->processors[] = $processor;
    }

    public function addHandler(string $channel, LogHandler $handler): void
    {
        $this->handlers[$channel][] = $handler;
    }

    private function createChannel(string $name): LogChannel
    {
        $handlers = $this->handlers[$name] ?? [];
        return new LogChannel($name, $handlers, $this->processors);
    }
}

class LogChannel
{
    private string $name;
    private array $handlers;
    private array $processors;

    public function __construct(string $name, array $handlers, array $processors)
    {
        $this->name = $name;
        $this->handlers = $handlers;
        $this->processors = $processors;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $record = new LogRecord(
            $this->name,
            $level,
            $message,
            $context,
            time()
        );

        foreach ($this->processors as $processor) {
            $record = $processor->process($record);
        }

        foreach ($this->handlers as $handler) {
            $handler->handle($record);
        }
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

class LogRecord
{
    private string $channel;
    private string $level;
    private string $message;
    private array $context;
    private int $timestamp;
    private array $extra = [];

    public function __construct(
        string $channel,
        string $level,
        string $message,
        array $context,
        int $timestamp
    ) {
        $this->channel = $channel;
        $this->level = $level;
        $this->message = $message;
        $this->context = $context;
        $this->timestamp = $timestamp;
    }

    public function getChannel(): string
    {
        return $this->channel;
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

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getExtra(): array
    {
        return $this->extra;
    }

    public function setExtra(string $key, $value): void
    {
        $this->extra[$key] = $value;
    }
}

interface LogHandler
{
    public function handle(LogRecord $record): void;
}

interface LogProcessor
{
    public function process(LogRecord $record): LogRecord;
}

class FileHandler implements LogHandler
{
    private string $path;
    private string $minLevel;
    private LogFormatter $formatter;

    public function handle(LogRecord $record): void
    {
        if (!$this->shouldHandle($record)) {
            return;
        }

        $formatted = $this->formatter->format($record);
        file_put_contents($this->path, $formatted . PHP_EOL, FILE_APPEND);
    }

    private function shouldHandle(LogRecord $record): bool
    {
        return LogLevel::isLevelHigherOrEqual($record->getLevel(), $this->minLevel);
    }
}

class DatabaseHandler implements LogHandler
{
    private $connection;

    public function handle(LogRecord $record): void
    {
        $this->connection->table('logs')->insert([
            'channel' => $record->getChannel(),
            'level' => $record->getLevel(),
            'message' => $record->getMessage(),
            'context' => json_encode($record->getContext()),
            'extra' => json_encode($record->getExtra()),
            'created_at' => $record->getTimestamp()
        ]);
    }
}

class LogLevel
{
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';

    private static array $levels = [
        self::EMERGENCY => 0,
        self::ALERT     => 1,
        self::CRITICAL  => 2,
        self::ERROR     => 3,
        self::WARNING   => 4,
        self::NOTICE    => 5,
        self::INFO      => 6,
        self::DEBUG     => 7,
    ];

    public static function isLevelHigherOrEqual(string $level1, string $level2): bool
    {
        return self::$levels[$level1] <= self::$levels[$level2];
    }
}
