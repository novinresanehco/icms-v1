<?php

namespace App\Core\Logging\Storage;

class LogStorage implements LogStorageInterface
{
    private ConnectionManager $connectionManager;
    private LogFormatter $formatter;
    private Config $config;

    public function __construct(
        ConnectionManager $connectionManager,
        LogFormatter $formatter,
        Config $config
    ) {
        $this->connectionManager = $connectionManager;
        $this->formatter = $formatter;
        $this->config = $config;
    }

    public function store(LogEntry $entry): bool
    {
        $connection = $this->connectionManager->getConnection();

        try {
            // Format log entry
            $formatted = $this->formatter->format($entry);

            // Get storage path
            $path = $this->getStoragePath($entry);

            // Ensure directory exists
            $this->ensureDirectoryExists(dirname($path));

            // Write with exclusive lock
            $result = $connection->put(
                $path,
                $formatted . PHP_EOL,
                $this->config->get('logging.file_permissions', 0644)
            );

            // Rotate logs if needed
            $this->rotateLogsIfNeeded();

            return $result;
        } catch (\Exception $e) {
            // Handle storage failure
            $this->handleStorageFailure($entry, $e);
            return false;
        }
    }

    protected function getStoragePath(LogEntry $entry): string
    {
        $baseDir = $this->config->get('logging.path');
        
        if ($this->config->get('logging.daily', false)) {
            return sprintf(
                '%s/%s-%s.log',
                $baseDir,
                $entry->getTimestamp()->format('Y-m-d'),
                $entry->