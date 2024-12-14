// app/Core/Logging/Logger.php
<?php

namespace App\Core\Logging;

use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\Log;
use App\Core\Logging\Handlers\LogHandler;
use App\Core\Logging\Formatters\LogFormatter;

class Logger
{
    private array $handlers = [];
    private array $processors = [];
    private LogFormatter $formatter;

    public function __construct(LogFormatter $formatter)
    {
        $this->formatter = $formatter;
    }

    public function addHandler(LogHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function addProcessor(callable $processor): void
    {
        $this->processors[] = $processor;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $record = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'datetime' => new \DateTime(),
            'extra' => []
        ];

        $record = $this->processRecord($record);
        $formatted = $this->formatter->format($record);

        foreach ($this->handlers as $handler) {
            if ($handler->isHandling($record)) {
                $handler->handle($formatted);
            }
        }
    }

    private function processRecord(array $record): array
    {
        foreach ($this->processors as $processor) {
            $record = $processor($record);
        }
        return $record;
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
}