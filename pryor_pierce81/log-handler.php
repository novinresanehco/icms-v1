<?php

namespace App\Core\Logging;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\LogHandlerException;
use Psr\Log\LogLevel;

class CriticalLogHandler implements LogHandlerInterface
{
    private SecurityManagerInterface $security;
    private string $path;
    private array $config;
    private $handle;

    public function __construct(
        SecurityManagerInterface $security,
        string $path,
        array $config = []
    ) {
        $this->security = $security;
        $this->path = $path;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function handle(array $record): bool
    {
        try {
            $this->security->validateContext('logging:handle');
            
            if (!$this->isHandling($record)) {
                return false;
            }

            $this->initializeHandle();
            $this->validateRecord($record);
            
            $formatted = $this->formatRecord($record);
            $this->writeRecord($formatted);

            return true;

        } catch (\Exception $e) {
            throw new LogHandlerException('Failed to handle log record', 0, $e);
        }
    }

    public function isHandling(array $record): bool
    {
        return $this->getLogLevel($record['level']) >= 
               $this->getLogLevel($this->config['minimum_level']);
    }

    private function initializeHandle(): void
    {
        if ($this->handle === null) {
            if (!file_exists($this->path)) {
                $directory = dirname($this->path);
                if (!file_exists($directory)) {
                    mkdir($directory, 0755, true);
                }
            }

            $this->handle = fopen($this->path, 'a');
            if ($this->handle === false) {
                throw new LogHandlerException('Failed to open log file');
            }

            $this->validateFileSize();
        }
    }

    private function validateFileSize(): void
    {
        $size = filesize($this->path);
        if ($size > $this->config['max_file_size']) {
            $this->rotateLog();
        }
    }

    private function rotateLog(): void
    {
        fclose($this->handle);
        $this->handle = null;

        $timestamp = date('Y-m-d-H-i-s');
        $newPath = "{$this->path}.{$timestamp}";
        
        rename($this->path, $newPath);
        $this->pruneOldLogs();
        
        $this->initializeHandle();
    }

    private function pruneOldLogs(): void
    {
        $pattern = $this->path . '.*';
        $files