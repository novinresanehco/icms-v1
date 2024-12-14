<?php

namespace App\Core\Notification\Analytics\Grouper;

class DataGrouper
{
    private array $groupers = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_groups' => 1000,
            'min_group_size' => 1
        ], $config);
    }

    public function addGrouper(string $name, GrouperInterface $grouper): void
    {
        $this->groupers[$name] = $grouper;
    }

    public function group(array $data, string $grouperName, array $options = []): array
    {
        if (!isset($this->groupers[$grouperName])) {
            throw new \InvalidArgumentException("Unknown grouper: {$grouperName}");
        }

        $startTime = microtime(true);
        try {
            $result = $this->groupers[$grouperName]->group($data, array_merge($this->config, $options));
            $this->recordMetrics($grouperName, $data, $result, microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($grouperName, $data, [], microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $grouperName, array $input, array $output, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$grouperName])) {
            $this->metrics[$grouperName] = [
                'total_operations' => 0,
                'successful_operations' => 0,
                'failed_operations' => 0,
                'total_duration' => 0,
                'total_input_size' => 0,
                'total_output_groups' => 0
            ];
        }

        $this->metrics[$grouperName]['total_operations']++;
        $this->metrics[$grouperName][$success ? 'successful_operations' : 'failed_operations']++;
        $this->metrics[$grouperName]['total_duration'] += $duration;
        $this->metrics[$grouperName]['total_input_size'] += count($input);
        $this->metrics[$grouperName]['total_output_groups'] += count($output);
    }
}

interface GrouperInterface
{
    public function group(array $data, array $options = []): array;
}

class FieldGrouper implements GrouperInterface
{
    public function group(array $data, array $options = []): array
    {
        $fields = $options['fields'] ?? [];
        if (empty($fields)) {
            throw new \InvalidArgumentException("No grouping fields specified");
        }

        $groups = [];
        foreach ($data as $item) {
            $key = $this->buildGroupKey($item, $fields);
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $item;
        }

        return $this->formatGroups($groups, $options);
    }

    private function buildGroupKey(array $item, array $fields): string
    {
        $keyParts = [];
        foreach ($fields as $field) {
            $keyParts[] = $item[$field] ?? 'null';
        }
        return implode('|', $keyParts);
    }

    private function formatGroups(array $groups, array $options): array
    {
        $minSize = $options['min_group_size'] ?? 1;
        $result = [];

        foreach ($groups as $key => $items) {
            if (count($items) >= $minSize) {
                $keyParts = explode('|', $key);
                $group = [
                    'key' => array_combine($options['fields'], $keyParts),
                    'count' => count($items),
                    'items' => $items
                ];
                $result[] = $group;
            }
        }

        return $result;
    }
}

class TimeGrouper implements GrouperInterface
{
    public function group(array $data, array $options = []): array
    {
        $timeField = $options['time_field'] ?? 'timestamp';
        $interval = $options['interval'] ?? 3600;

        $groups = [];
        foreach ($data as $item) {
            if (!isset($item[$timeField])) {
                continue;
            }

            $timestamp = is_numeric($item[$timeField]) ? $item[$timeField] : strtotime($item[$timeField]);
            $bucket = floor($timestamp / $interval) * $interval;

            if (!isset($groups[$bucket])) {
                $groups[$bucket] = [];
            }
            $groups[$bucket][] = $item;
        }

        return $this->formatTimeGroups($groups, $options);
    }

    private function formatTimeGroups(array $groups, array $options): array
    {
        $result = [];
        foreach ($groups as $timestamp => $items) {
            $result[] = [
                'timestamp' => $timestamp,
                'datetime' => date('Y-m-d H:i:s', $timestamp),
                'count' => count($items),
                'items' => $items
            ];
        }

        usort($result, function($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $result;
    }
}

class ValueRangeGrouper implements GrouperInterface
{
    public function group(array $data, array $options = []): array
    {
        $field = $options['field'] ?? null;
        if (!$field) {
            throw new \InvalidArgumentException("No field specified for value range grouping");
        }

        $ranges = $options['ranges'] ?? $this->calculateRanges($data, $field);
        $groups = array_fill_keys(array_keys($ranges), []);

        foreach ($data as $item) {
            if (!isset($item[$field])) {
                continue;
            }

            $value = $item[$field];
            $range = $this->findRange($value, $ranges);
            if ($range !== null) {
                $groups[$range][] = $item;
            }
        }

        return $this->formatRangeGroups($groups, $ranges, $options);
    }

    private function calculateRanges(array $data, string $field): array
    {
        $values = array_column($data, $field);
        $min = min($values);
        $max = max($values);
        $range = $max - $min;
        $bucketSize = $range / 10;

        $ranges = [];
        for ($i = $min; $i < $max; $i += $bucketSize) {
            $ranges["{$i}-" . ($i + $bucketSize)] = [$i, $i + $bucketSize];
        }

        return $ranges;
    }

    private function findRange($value, array $ranges): ?string
    {
        foreach ($ranges as $label => $range) {
            if ($value >= $range[0] && $value < $range[1]) {
                return $label;
            }
        }
        return null;
    }

    private function formatRangeGroups(array $groups, array $ranges, array $options): array
    {
        $result = [];
        foreach ($groups as $range => $items) {
            if (!empty($items)) {
                $result[] = [
                    'range' => $range,
                    'bounds' => $ranges[$range],
                    'count' => count($items),
                    'items' => $items
                ];
            }
        }
        return $result;
    }
}
