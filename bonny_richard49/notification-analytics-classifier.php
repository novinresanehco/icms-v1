<?php

namespace App\Core\Notification\Analytics\Classifier;

class DataClassifier
{
    private array $classifiers = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'min_confidence' => 0.7,
            'max_categories' => 100
        ], $config);
    }

    public function addClassifier(string $name, ClassifierInterface $classifier): void
    {
        $this->classifiers[$name] = $classifier;
    }

    public function classify(array $data, string $classifierName, array $options = []): array
    {
        if (!isset($this->classifiers[$classifierName])) {
            throw new \InvalidArgumentException("Unknown classifier: {$classifierName}");
        }

        $startTime = microtime(true);
        try {
            $result = $this->classifiers[$classifierName]->classify($data, array_merge($this->config, $options));
            $this->recordMetrics($classifierName, $data, $result, microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($classifierName, $data, [], microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function train(array $trainingData, string $classifierName, array $options = []): void
    {
        if (!isset($this->classifiers[$classifierName])) {
            throw new \InvalidArgumentException("Unknown classifier: {$classifierName}");
        }

        $startTime = microtime(true);
        try {
            $this->classifiers[$classifierName]->train($trainingData, $options);
            $this->recordMetrics($classifierName . '_training', $trainingData, [], microtime(true) - $startTime, true);
        } catch (\Exception $e) {
            $this->recordMetrics($classifierName . '_training', $trainingData, [], microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $operation, array $input, array $output, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$operation])) {
            $this->metrics[$operation] = [
                'total_operations' => 0,
                'successful_operations' => 0,
                'failed_operations' => 0,
                'total_duration' => 0,
                'total_items' => 0,
                'classified_items' => 0
            ];
        }

        $metrics = &$this->metrics[$operation];
        $metrics['total_operations']++;
        $metrics[$success ? 'successful_operations' : 'failed_operations']++;
        $metrics['total_duration'] += $duration;
        $metrics['total_items'] += count($input);
        $metrics['classified_items'] += count($output);
    }
}

interface ClassifierInterface
{
    public function classify(array $data, array $options = []): array;
    public function train(array $trainingData, array $options = []): void;
}

class RuleBasedClassifier implements ClassifierInterface
{
    private array $rules = [];

    public function classify(array $data, array $options = []): array
    {
        $minConfidence = $options['min_confidence'] ?? 0.7;
        $result = [];

        foreach ($data as $item) {
            $classifications = [];
            foreach ($this->rules as $category => $rule) {
                $confidence = $this->evaluateRule($item, $rule);
                if ($confidence >= $minConfidence) {
                    $classifications[] = [
                        'category' => $category,
                        'confidence' => $confidence
                    ];
                }
            }
            $result[] = [
                'item' => $item,
                'classifications' => $classifications
            ];
        }

        return $result;
    }

    public function train(array $trainingData, array $options = []): void
    {
        foreach ($trainingData as $rule) {
            $this->addRule($rule['category'], $rule['conditions']);
        }
    }

    public function addRule(string $category, array $conditions): void
    {
        $this->rules[$category] = $conditions;
    }

    private function evaluateRule(array $item, array $conditions): float
    {
        $matchedConditions = 0;
        $totalConditions = count($conditions);

        foreach ($conditions as $field => $condition) {
            if ($this->matchesCondition($item[$field] ?? null, $condition)) {
                $matchedConditions++;
            }
        }

        return $totalConditions > 0 ? $matchedConditions / $totalConditions : 0;
    }

    private function matchesCondition($value, $condition): bool
    {
        if (is_array($condition)) {
            $operator = key($condition);
            $target = current($condition);

            return match($operator) {
                'eq' => $value === $target,
                'gt' => $value > $target,
                'lt' => $value < $target,
                'in' => in_array($value, $target),
                'regex' => preg_match($target, $value),
                default => false
            };
        }

        return $value === $condition;
    }
}

class BayesianClassifier implements ClassifierInterface
{
    private array $categories = [];
    private array $wordFrequencies = [];
    private int $totalDocuments = 0;

    public function classify(array $data, array $options = []): array
    {
        $result = [];

        foreach ($data as $item) {
            $text = $this->extractText($item);
            $words = $this->tokenize($text);
            $scores = $this->calculateCategoryScores($words);
            
            arsort($scores);
            $result[] = [
                'item' => $item,
                'classifications' => array_map(function($score) {
                    return ['confidence' => $score];
                }, $scores)
            ];
        }

        return $result;
    }

    public function train(array $trainingData, array $options = []): void
    {
        foreach ($trainingData as $item) {
            $category = $item['category'];
            $text = $this->extractText($item);
            $words = $this->tokenize($text);

            if (!isset($this->categories[$category])) {
                $this->categories[$category] = 0;
            }
            $this->categories[$category]++;
            $this->totalDocuments++;

            foreach ($words as $word) {
                if (!isset($this->wordFrequencies[$category][$word])) {
                    $this->wordFrequencies[$category][$word] = 0;
                }
                $this->wordFrequencies[$category][$word]++;
            }
        }
    }

    private function extractText(array $item): string
    {
        return implode(' ', array_filter($item, 'is_string'));
    }

    private function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        return array_filter(explode(' ', $text));
    }

    private function calculateCategoryScores(array $words): array
    {
        $scores = [];

        foreach ($this->categories as $category => $count) {
            $score = log($count / $this->totalDocuments);
            
            foreach ($words as $word) {
                $wordFreq = $this->wordFrequencies[$category][$word] ?? 0;
                $score += log(($wordFreq + 1) / ($count + 2));
            }

            $scores[$category] = exp($score);
        }

        return $scores;
    }
}
