<?php

namespace App\Core\Logging\Formatters;

class AdvancedLogFormatter implements LogFormatterInterface
{
    protected array $formats;
    protected array $processors;
    
    public function __construct(array $formats = [], array $processors = [])
    {
        $this->formats = $formats;
        $this->processors = $processors;
    }

    public function format(LogEntry $entry): string
    {
        // Pre-process entry
        $processedEntry = $this->processEntry($entry);

        // Get format for level
        $format = $this->getFormatForLevel($entry->level);

        // Format basic entry
        $formatted = $this->formatBasicEntry($processedEntry, $format);

        // Add context if present
        if (!empty($processedEntry->context)) {
            $formatted .= $this->formatContext($processedEntry->context);
        }

        // Add extra formatting for specific levels
        $formatted = $this->addLevelSpecificFormatting($formatted, $processedEntry);

        return $formatted;
    }

    protected function processEntry(LogEntry $entry): LogEntry
    {
        $processed = clone $entry;

        foreach ($this->processors as $processor) {
            $processed = $processor->process($processed);
        }

        return $processed;
    }

    protected function getFormatForLevel(string $level): string
    {
        return $this->formats[$level] ?? $this->formats['default'];
    }

    protected function formatBasicEntry(LogEntry $entry, string $format): string
    {
        return strtr($format, [
            '{timestamp}' => $entry->timestamp->format('Y-m-d H:i:s.u'),
            '{level}' => strtoupper($entry->level),
            '{message}' => $entry->message,
            '{environment}' => config('app.env'),
            '{process_id}' => getmypid()
        ]);
    }

    protected function formatContext(array $context): string
    {
        // Convert context to JSON with special handling
        return json_encode($context, JSON_UNESCAPED_SLASHES | 
                                   JSON_UNESCAPED_UNICODE | 
                                   JSON_PRETTY_PRINT);
    }

    protected function addLevelSpecificFormatting(string $formatted, LogEntry $entry): string
    {
        switch ($entry->level) {
            case 'emergency':
            case 'alert':
            case 'critical':
                return "!!! {$formatted} !!!";

            case 'error':
                return "*** {$formatted} ***";

            case 'warning':
                return "* {$formatted} *";

            default:
                return $formatted;
        }
    }
}
