<?php

namespace App\Core\Notification\Analytics\Indexing;

class AnalyticsIndexer
{
    private array $indices = [];
    private array $analyzers = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_index_size' => 1000000,
            'index_threshold' => 0.7,
            'optimize_interval' => 3600
        ], $config);
    }

    public function createIndex(string $name, array $fields, array $options = []): void
    {
        $this->indices[$name] = [
            'fields' => $fields,
            'data' => [],
            'options' => array_merge([
                'analyzers' => [],
                'tokenizer' => 'standard',
                'filters' => []
            ], $options)
        ];
    }

    public function addAnalyzer(string $name, AnalyzerInterface $analyzer): void
    {
        $this->analyzers[$name] = $analyzer;
    }

    public function index(string $indexName, array $document): bool
    {
        if (!isset($this->indices[$indexName])) {
            throw new \InvalidArgumentException("Index not found: {$indexName}");
        }

        $startTime = microtime(true);
        try {
            $analyzedDocument = $this->analyzeDocument($document, $this->indices[$indexName]['options']);
            $this->indices[$indexName]['data'][] = $analyzedDocument;
            
            $this->recordMetrics($indexName, 'index', microtime(true) - $startTime, true);
            return true;
        } catch (\Exception $e) {
            $this->recordMetrics($indexName, 'index', microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function search(string $indexName, array $query): array
    {
        if (!isset($this->indices[$indexName])) {
            throw new \InvalidArgumentException("Index not found: {$indexName}");
        }

        $startTime = microtime(true);
        try {
            $results = $this->performSearch($this->indices[$indexName], $query);
            $this->recordMetrics($indexName, 'search', microtime(true) - $startTime, true);
            return $results;
        } catch (\Exception $e) {
            $this->recordMetrics($indexName, 'search', microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function optimize(string $indexName): void
    {
        if (!isset($this->indices[$indexName])) {
            throw new \InvalidArgumentException("Index not found: {$indexName}");
        }

        $startTime = microtime(true);
        try {
            $this->optimizeIndex($this->indices[$indexName]);
            $this->recordMetrics($indexName, 'optimize', microtime(true) - $startTime, true);
        } catch (\Exception $e) {
            $this->recordMetrics($indexName, 'optimize', microtime(true) - $startTime, false);
            throw $e;
        }
    }

    private function analyzeDocument(array $document, array $options): array
    {
        $analyzed = [];
        foreach ($document as $field => $value) {
            if (isset($options['analyzers'][$field])) {
                $analyzer = $this->analyzers[$options['analyzers'][$field]];
                $analyzed[$field] = $analyzer->analyze($value);
            } else {
                $analyzed[$field] = $value;
            }
        }
        return $analyzed;
    }

    private function performSearch(array $index, array $query): array
    {
        $results = [];
        foreach ($index['data'] as $document) {
            if ($this->matchesQuery($document, $query)) {
                $results[] = $document;
            }
        }
        return $results;
    }

    private function matchesQuery(array $document, array $query): bool
    {
        foreach ($query as $field => $condition) {
            if (!isset($document[$field])) {
                return false;
            }

            if (!$this->evaluateCondition($document[$field], $condition)) {
                return false;
            }
        }
        return true;
    }

    private function evaluateCondition($value, $condition): bool
    {
        if (is_array($condition)) {
            $operator = key($condition);
            $target = current($condition);

            switch ($operator) {
                case '$eq':
                    return $value === $target;
                case '$gt':
                    return $value > $target;
                case '$lt':
                    return $value < $target;
                case '$gte':
                    return $value >= $target;
                case '$lte':
                    return $value <= $target;
                case '$in':
                    return in_array($value, $target);
                case '$like':
                    return preg_match("/{$target}/i", $value);
                default:
                    throw new \InvalidArgumentException("Unknown operator: {$operator}");
            }
        }

        return $value === $condition;
    }

    private function optimizeIndex(array &$index): void
    {
        // Remove duplicates
        $index['data'] = array_unique($index['data'], SORT_REGULAR);

        // Sort for faster searching
        usort($index['data'], function($a, $b) {
            return strcmp(serialize($a), serialize($b));
        });

        // Compact storage if needed
        if (count($index['data']) > $this->config['max_index_size']) {
            $index['data'] = array_slice($index['data'], -$this->config['max_index_size']);
        }
    }

    private function recordMetrics(string $index, string $operation, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$index])) {
            $this->metrics[$index] = [
                'operations' => 0,
                'successful' => 0,
                'failed' => 0,
                'total_duration' => 0,
                'average_duration' => 0
            ];
        }

        $this->metrics[$index]['operations']++;
        $this->metrics[$index][$success ? 'successful' : 'failed']++;
        $this->metrics[$index]['total_duration'] += $duration;
        $this->metrics[$index]['average_duration'] = 
            $this->metrics[$index]['total_duration'] / $this->metrics[$index]['operations'];
    }
}

interface AnalyzerInterface
{
    public function analyze($value): array;
}

class StandardAnalyzer implements AnalyzerInterface
{
    public function analyze($value): array
    {
        if (!is_string($value)) {
            return [$value];
        }

        // Convert to lowercase
        $value = strtolower($value);

        // Remove punctuation
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value);

        // Split into tokens
        $tokens = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        // Remove duplicates
        return array_unique($tokens);
    }
}

class NGramAnalyzer implements AnalyzerInterface
{
    private int $minGram;
    private int $maxGram;

    public function __construct(int $minGram = 2, int $maxGram = 3)
    {
        $this->minGram = $minGram;
        $this->maxGram = $maxGram;
    }

    public function analyze($value): array
    {
        if (!is_string($value)) {
            return [$value];
        }

        $tokens = [];
        $value = strtolower($value);
        $len = mb_strlen($value);

        for ($i = 0; $i < $len; $i++) {
            for ($size = $this->minGram; $size <= $this->maxGram && ($i + $size) <= $len; $size++) {
                $tokens[] = mb_substr($value, $i, $size);
            }
        }

        return array_unique($tokens);
    }
}
