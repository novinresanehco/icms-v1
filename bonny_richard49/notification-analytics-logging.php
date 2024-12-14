<?php

namespace App\Core\Notification\Analytics\Logging;

class AnalyticsLogger
{
    private string $logPath;
    private array $config;
    private array $buffer = [];

    public function __construct(string $logPath, array $config = [])
    {
        $this->logPath = $logPath;
        $this->config = array_merge([
            'buffer_size' => 100,
            'rotation_size' => 10485760, // 10MB
            'max_files' => 10
        ], $config);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $entry = $this->formatLogEntry($level, $message, $context);
        $this->buffer[] = $entry;

        if (count($this->buffer) >= $this->config['buffer_size']) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $this->rotateLogFileIfNeeded();
        $this->writeToFile(implode(PHP_EOL, $this->buffer) . PHP_EOL);
        $this->buffer = [];
    }

    private function formatLogEntry(string $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = json_encode($context);
        return "[{$timestamp}] {$level}: {$message} {$contextJson}";
    }

    private function rotateLogFileIfNeeded(): void
    {
        if (!file_exists($this->logPath)) {
            return;
        }

        if (filesize($this->logPath) >= $this->config['rotation_size']) {
            $this->rotateFiles();
        }
    }

    private function rotateFiles(): void
    {
        for ($i = $this->config['max_files'] - 1; $i >= 0; $i--) {
            $oldFile = $this->getRotatedFileName($i);
            $newFile = $this->getRotatedFileName($i + 1);
            
            if (file_exists($oldFile)) {
                rename($oldFile, $newFile);
            }
        }
    }

    private function getRotatedFileName(int $index): string
    {
        return $index === 0 ? $this->logPath : "{$this->logPath}.{$index}";
    }

    private function writeToFile(string $data): void
    {
        file_put_contents($this->logPath, $data, FILE_APPEND | LOCK_EX);
    }
}
