<?php

namespace App\Core\Notification\Analytics\Pattern;

class PatternMatcher
{
    private array $patterns = [];
    private array $cache = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'cache_enabled' => true,
            'min_confidence' => 0.8,
            'max_patterns' => 100
        ], $config);
    }

    public function addPattern(string $name, array $pattern): void
    {
        $this->patterns[$name] = $pattern;
    }

    public function match(array $data): array
    {
        $results = [];
        foreach ($this->patterns as $name => $pattern) {
            $confidence = $this->calculateConfidence($data, $pattern);
            if ($confidence >= $this->config['min_confidence']) {
                $results[$name] = [
                    'confidence' => $confidence,
                    'matches' => $this->extractMatches($data, $pattern)
                ];
            }
        }
        return $results;
    }

    public function learn(array $trainingData): void
    {
        foreach ($trainingData as $example) {
            $this->extractPattern($example);
        }
    }

    private function calculateConfidence(array $data, array $pattern): float
    {
        $matches = 0;
        $total = count($pattern['required_fields'] ?? []);

        foreach ($pattern['required_fields'] as $field) {
            if (isset($data[$field]) && $this->validateField($data[$field], $pattern['constraints'][$field] ?? null)) {
                $matches++;
            }
        }

        return $total > 0 ? $matches / $total : 0;
    }

    private function extractMatches(array $data, array $pattern): array
    {
        $matches = [];
        foreach ($pattern['extract_fields'] ?? [] as $field) {
            if (isset($data[$field])) {
                $matches[$field] = $data[$field];
            }
        }
        return $matches;
    }

    private function validateField($value, ?array $constraints): bool
    {
        if (!$constraints) {
            return true;
        }

        foreach ($constraints as $type => $constraint) {
            switch ($type) {
                case 'type':
                    if (gettype($value) !== $constraint) {
                        return false;
                    }
                    break;
                case 'range':
                    if ($value < $constraint[0] || $value > $constraint[1]) {
                        return false;
                    }
                    break;
                case 'regex':
                    if (!preg_match($constraint, (string)$value)) {
                        return false;
                    }
                    break;
                case 'enum':
                    if (!in_array($value, $constraint)) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    private function extractPattern(array $example): void
    {
        $pattern = [
            'required_fields' => array_keys($example),
            'constraints' => []
        ];

        foreach ($example as $field => $value) {
            $pattern['constraints'][$field] = $this->inferConstraints($value);
        }

        $hash = $this->hashPattern($pattern);
        if (!isset($this->patterns[$hash])) {
            $this->patterns[$hash] = $pattern;
        }
    }

    private function inferConstraints($value): array
    {
        $constraints = ['type' => gettype($value)];

        if (is_numeric($value)) {
            $constraints['range'] = [-PHP_FLOAT_MAX, PHP_FLOAT_MAX];
        } elseif (is_string($value)) {
            $constraints['regex'] = '/.*/';
        }

        return $constraints;
    }

    private function hashPattern(array $pattern): string
    {
        return md5(serialize($pattern));
    }
}

class PatternAnalyzer
{
    private PatternMatcher $matcher;
    private array $metrics = [];

    public function __construct(PatternMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    public function analyze(array $data): array
    {
        $matches = $this->matcher->match($data);
        $this->updateMetrics($matches);

        return [
            'matches' => $matches,
            'summary' => $this->generateSummary($matches),
            'insights' => $this->generateInsights($matches)
        ];
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function updateMetrics(array $matches): void
    {
        foreach ($matches as $pattern => $result) {
            if (!isset($this->metrics[$pattern])) {
                $this->metrics[$pattern] = [
                    'matches' => 0,
                    'total_confidence' => 0,
                    'high_confidence_matches' => 0
                ];
            }

            $this->metrics[$pattern]['matches']++;
            $this->metrics[$pattern]['total_confidence'] += $result['confidence'];

            if ($result['confidence'] > 0.9) {
                $this->metrics[$pattern]['high_confidence_matches']++;
            }
        }
    }

    private function generateSummary(array $matches): array
    {
        return [
            'total_matches' => count($matches),
            'avg_confidence' => $this->calculateAverageConfidence($matches),
            'patterns_found' => array_keys($matches)
        ];
    }

    private function calculateAverageConfidence(array $matches): float
    {
        if (empty($matches)) {
            return 0;
        }

        $sum = array_sum(array_column($matches, 'confidence'));
        return $sum / count($matches);
    }

    private function generateInsights(array $matches): array
    {
        $insights = [];

        foreach ($matches as $pattern => $result) {
            if ($result['confidence'] > 0.9) {
                $insights[] = [
                    'type' => 'high_confidence',
                    'pattern' => $pattern,
                    'confidence' => $result['confidence']
                ];
            }
        }

        return $insights;
    }
}
