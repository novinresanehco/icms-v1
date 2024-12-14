<?php

namespace App\Core\Notification\Analytics\Loading;

class AnalyticsLoader
{
    private array $loaders = [];
    private array $extractors = [];
    private array $metrics = [];

    public function registerLoader(string $name, LoaderInterface $loader): void
    {
        $this->loaders[$name] = $loader;
    }

    public function registerExtractor(string $name, ExtractorInterface $extractor): void
    {
        $this->extractors[$name] = $extractor;
    }

    public function load(string $loader, array $data, array $options = []): array
    {
        if (!isset($this->loaders[$loader])) {
            throw new \InvalidArgumentException("Unknown loader: {$loader}");
        }

        $startTime = microtime(true);
        try {
            $result = $this->loaders[$loader]->load($data, $options);
            $this->recordMetrics($loader, 'load', count($data), microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($loader, 'load', count($data), microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function extract(string $extractor, $source, array $options = []): array
    {
        if (!isset($this->extractors[$extractor])) {
            throw new \InvalidArgumentException("Unknown extractor: {$extractor}");
        }

        $startTime = microtime(true);
        try {
            $result = $this->extractors[$extractor]->extract($source, $options);
            $this->recordMetrics($extractor, 'extract', is_array($source) ? count($source) : 1, microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($extractor, 'extract', is_array($source) ? count($source) : 1, microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $component, string $operation, int $itemCount, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$component])) {
            $this->metrics[$component] = [
                'operations' => 0,
                'successful' => 0,
                'failed' => 0,
                'total_duration' => 0,
                'items_processed' => 0
            ];
        }

        $metrics = &$this->metrics[$component];
        $metrics['operations']++;
        $metrics[$success ? 'successful' : 'failed']++;
        $metrics['total_duration'] += $duration;
        $metrics['items_processed'] += $itemCount;
    }
}

interface LoaderInterface
{
    public function load(array $data, array $options = []): array;
}

interface ExtractorInterface
{
    public function extract($source, array $options = []): array;
}

class DatabaseLoader implements LoaderInterface
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    public function load(array $data, array $options = []): array
    {
        $table = $options['table'] ?? null;
        if (!$table) {
            throw new \InvalidArgumentException("Table name is required");
        }

        $columns = implode(', ', array_keys(reset($data)));
        $placeholders = implode(', ', array_fill(0, count(reset($data)), '?'));
        $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

        $stmt = $this->db->prepare($query);
        $loaded = [];

        foreach ($data as $row) {
            try {
                $stmt->execute(array_values($row));
                $loaded[] = array_merge($row, ['id' => $this->db->lastInsertId()]);
            } catch (\PDOException $e) {
                throw new LoaderException("Failed to load data: " . $e->getMessage());
            }
        }

        return $loaded;
    }
}

class FileExtractor implements ExtractorInterface
{
    public function extract($source, array $options = []): array
    {
        $format = $options['format'] ?? $this->detectFormat($source);
        
        switch ($format) {
            case 'csv':
                return $this->extractCsv($source, $options);
            case 'json':
                return $this->extractJson($source, $options);
            default:
                throw new \InvalidArgumentException("Unsupported format: {$format}");
        }
    }

    private function detectFormat(string $source): string
    {
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        return strtolower($extension);
    }

    private function extractCsv(string $source, array $options): array
    {
        $delimiter = $options['delimiter'] ?? ',';
        $enclosure = $options['enclosure'] ?? '"';
        $escape = $options['escape'] ?? '\\';
        $header = $options['header'] ?? true;

        $handle = fopen($source, 'r');
        if ($handle === false) {
            throw new ExtractorException("Failed to open file: {$source}");
        }

        $data = [];
        $headers = $header ? fgetcsv($handle, 0, $delimiter, $enclosure, $escape) : null;

        while (($row = fgetcsv($handle, 0, $delimiter, $enclosure, $escape)) !== false) {
            if ($headers) {
                $data[] = array_combine($headers, $row);
            } else {
                $data[] = $row;
            }
        }

        fclose($handle);
        return $data;
    }

    private function extractJson(string $source, array $options): array
    {
        $content = file_get_contents($source);
        if ($content === false) {
            throw new ExtractorException("Failed to read file: {$source}");
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ExtractorException("Failed to parse JSON: " . json_last_error_msg());
        }

        return $data;
    }
}

class LoaderException extends \Exception {}
class ExtractorException extends \Exception {}
