<?php

namespace App\Core\Notification\Analytics\Inspector;

class AnalyticsInspector
{
    private array $inspectors = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_depth' => 10,
            'timeout' => 30,
            'sampling_rate' => 1.0
        ], $config);
    }

    public function addInspector(string $name, InspectorInterface $inspector): void
    {
        $this->inspectors[$name] = $inspector;
    }

    public function inspect(array $data, array $options = []): InspectionResult
    {
        $startTime = microtime(true);
        $results = [];

        try {
            foreach ($this->inspectors as $name => $inspector) {
                $results[$name] = $inspector->inspect($data, array_merge($this->config, $options));
            }

            $this->recordMetrics('inspect', microtime(true) - $startTime, true);
            return new InspectionResult($results);

        } catch (\Exception $e) {
            $this->recordMetrics('inspect', microtime(true) - $startTime, false);
            throw new InspectionException('Inspection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $operation, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = [
                'total' => 0,
                'successful' => 0,
                'failed' => 0,
                'total_duration' => 0,
                'average_duration' => 0
            ];
        }

        $this->metrics[$operation]['total']++;
        $this->metrics[$operation][$success ? 'successful' : 'failed']++;
        $this->metrics[$operation]['total_duration'] += $duration;
        $this->metrics[$operation]['average_duration'] = 
            $this->metrics[$operation]['total_duration'] / $this->metrics[$operation]['total'];
    }
}

interface InspectorInterface
{
    public function inspect(array $data, array $options = []): array;
}

class StructureInspector implements InspectorInterface
{
    public function inspect(array $data, array $options = []): array
    {
        $structure = [
            'total_items' => count($data),
            'depth' => 0,
            'fields' => [],
            'types' => []
        ];

        foreach ($data as $item) {
            $this->inspectItem($item, $structure, 0, $options['max_depth'] ?? 10);
        }

        return $structure;
    }

    private function inspectItem($item, array &$structure, int $depth, int $maxDepth): void
    {
        $structure['depth'] = max($structure['depth'], $depth);

        if (is_array($item)) {
            if ($depth < $maxDepth) {
                foreach ($item as $key => $value) {
                    if (!isset($structure['fields'][$key])) {
                        $structure['fields'][$key] = [
                            'count' => 0,
                            'types' => []
                        ];
                    }
                    $structure['fields'][$key]['count']++;
                    $structure['fields'][$key]['types'][gettype($value)] = true;
                    $this->inspectItem($value, $structure, $depth + 1, $maxDepth);
                }
            }
        } else {
            $type = gettype($item);
            $structure['types'][$type] = ($structure['types'][$type] ?? 0) + 1;
        }
    }
}

class PatternInspector implements InspectorInterface
{
    public function inspect(array $data, array $options = []): array
    {
        $patterns = [
            'sequences' => [],
            'correlations' => [],
            'anomalies' => []
        ];

        if (!empty($data)) {
            $this->detectSequences($data, $patterns);
            $this->detectCorrelations($data, $patterns);
            $this->detectAnomalies($data, $patterns);
        }

        return $patterns;
    }

    private function detectSequences(array $data, array &$patterns): void
    {
        // Detect common sequences in data
        $sequence = [];
        $prevItem = null;

        foreach ($data as $item) {
            if ($prevItem !== null) {
                $key = $this->createSequenceKey($prevItem, $item);
                $sequence[$key] = ($sequence[$key] ?? 0) + 1;
            }
            $prevItem = $item;
        }

        $patterns['sequences'] = array_filter($sequence, function($count) {
            return $count > 1;
        });
    }

    private function detectCorrelations(array $data, array &$patterns): void
    {
        $fields = $this->extractFields($data);
        
        foreach ($fields as $field1 => $values1) {
            foreach ($fields as $field2 => $values2) {
                if ($field1 !== $field2) {
                    $correlation = $this->calculateCorrelation($values1, $values2);
                    if (abs($correlation) > 0.5) {
                        $patterns['correlations'][] = [
                            'fields' => [$field1, $field2],
                            'correlation' => $correlation
                        ];
                    }
                }
            }
        }
    }

    private function detectAnomalies(array $data, array &$patterns): void
    {
        $fields = $this->extractFields($data);
        
        foreach ($fields as $field => $values) {
            if (is_numeric($values[0])) {
                $stats = $this->calculateStats($values);
                $threshold = $stats['std'] * 2;
                
                foreach ($values as $index => $value) {
                    if (abs($value - $stats['mean']) > $threshold) {
                        $patterns['anomalies'][] = [
                            'field' => $field,
                            'index' => $index,
                            'value' => $value,
                            'expected' => $stats['mean'],
                            'deviation' => abs($value - $stats['mean'])
                        ];
                    }
                }
            }
        }
    }

    private function createSequenceKey($prev, $curr): string
    {
        return md5(serialize($prev) . '_' . serialize($curr));
    }

    private function extractFields(array $data): array
    {
        $fields = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                foreach ($item as $key => $value) {
                    if (!isset($fields[$key])) {
                        $fields[$key] = [];
                    }
                    $fields[$key][] = $value;
                }
            }
        }
        return $fields;
    }

    private function calculateCorrelation(array $x, array $y): float
    {
        $n = count($x);
        if ($n !== count($y) || $n === 0) {
            return 0;
        }

        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;
        $sumY2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
            $sumY2 += $y[$i] * $y[$i];
        }

        $denominator = sqrt(($n * $sumX2 - $sumX * $sumX) * ($n * $sumY2 - $sumY * $sumY));
        
        if ($denominator == 0) {
            return 0;
        }

        return ($n * $sumXY - $sumX * $sumY) / $denominator;
    }

    private function calculateStats(array $values): array
    {
        $n = count($values);
        $mean = array_sum($values) / $n;
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        $variance /= $n;

        return [
            'mean' => $mean,
            'std' => sqrt($variance)
        ];
    }
}

class InspectionResult
{
    private array $results;

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getResultForInspector(string $inspector): ?array
    {
        return $this->results[$inspector] ?? null;
    }
}

class InspectionException extends \Exception {}
