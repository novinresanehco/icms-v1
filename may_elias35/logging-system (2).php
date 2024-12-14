// File: app/Core/Log/Manager/LogManager.php
<?php

namespace App\Core\Log\Manager;

class LogManager
{
    protected array $handlers = [];
    protected LogFormatter $formatter;
    protected LogConfig $config;

    public function log(string $level, string $message, array $context = []): void
    {
        $log = $this->formatter->format($level, $message, $context);

        foreach ($this->getHandlers($level) as $handler) {
            try {
                $handler->handle($log);
            } catch (\Exception $e) {
                $this->handleFailure($e);
            }
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

    protected function getHandlers(string $level): array
    {
        return array_filter($this->handlers, function($handler) use ($level) {
            return $handler->handles($level);
        });
    }
}

// File: app/Core/Log/Handler/FileLogHandler.php
<?php

namespace App\Core\Log\Handler;

class FileLogHandler implements LogHandlerInterface
{
    protected string $path;
    protected FileSystem $filesystem;
    protected FileRotator $rotator;

    public function handle(LogEntry $log): void
    {
        $content = $this->formatLog($log);
        
        $this->filesystem->append($this->path, $content);
        
        if ($this->shouldRotate()) {
            $this->rotator->rotate($this->path);
        }
    }

    public function handles(string $level): bool
    {
        return true;
    }

    protected function formatLog(LogEntry $log): string
    {
        return sprintf(
            "[%s] %s: %s %s\n",
            $log->getDateTime()->format('Y-m-d H:i:s'),
            $log->getLevel(),
            $log->getMessage(),
            json_encode($log->getContext())
        );
    }
}

// File: app/Core/Log/Processor/LogProcessor.php
<?php

namespace App\Core\Log\Processor;

class LogProcessor
{
    protected array $processors = [];
    protected ContextBuilder $contextBuilder;
    protected ProcessorConfig $config;

    public function process(LogEntry $entry): LogEntry
    {
        $context = $this->contextBuilder->build();
        $entry->addContext($context);

        foreach ($this->processors as $processor) {
            if ($processor->shouldProcess($entry)) {
                $entry = $processor->process($entry);
            }
        }

        return $entry;
    }

    public function addProcessor(Processor $processor): void
    {
        $this->processors[] = $processor;
    }
}

// File: app/Core/Log/Formatter/LogFormatter.php
<?php

namespace App\Core\Log\Formatter;

class LogFormatter
{
    protected array $formatters = [];
    protected FormatterConfig $config;

    public function format(string $level, string $message, array $context = []): LogEntry
    {
        $entry = new LogEntry($level, $message, $context);

        foreach ($this->formatters as $formatter) {
            if ($formatter->supports($entry)) {
                $entry = $formatter->format($entry);
            }
        }

        return $entry;
    }

    public function addFormatter(Formatter $formatter): void
    {
        $this->formatters[] = $formatter;
    }
}
