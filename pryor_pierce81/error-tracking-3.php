<?php

namespace App\Core\Monitoring\Error\Tracking;

class ErrorTracker {
    private ErrorRepository $repository;
    private ErrorClassifier $classifier;
    private SimilarityDetector $similarityDetector;
    private ErrorGrouper $grouper;
    private ErrorMetrics $metrics;

    public function track(\Throwable $error): void 
    {
        $errorRecord = new ErrorRecord($error);
        $classification = $this->classifier->classify($errorRecord);
        $similarErrors = $this->similarityDetector->findSimilar($errorRecord);
        
        if (!empty($similarErrors)) {
            $group = $this->grouper->groupWith($errorRecord, $similarErrors);
            $errorRecord->setGroup($group);
        }

        $this->repository->save($errorRecord);
        $this->metrics->recordError($errorRecord, $classification);
    }
}

class ErrorRecord {
    private string $id;
    private string $type;
    private string $message;
    private string $stackTrace;
    private array $context;
    private ?string $groupId;
    private float $timestamp;

    public function __construct(\Throwable $error) 
    {
        $this->id = uniqid('err_', true);
        $this->type = get_class($error);
        $this->message = $error->getMessage();
        $this->stackTrace = $error->getTraceAsString();
        $this->context = $this->captureContext();
        $this->timestamp = microtime(true);
    }

    private function captureContext(): array 
    {
        return [
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'session_id' => session_id() ?: null,
            'memory_usage' => memory_get_usage(true),
            'php_version' => PHP_VERSION
        ];
    }

    public function setGroup(ErrorGroup $group): void 
    {
        $this->groupId = $group->getId();
    }
}

class ErrorClassifier {
    private array $rules = [];
    private array $patterns = [];

    public function classify(ErrorRecord $error): ErrorClassification 
    {
        $severity = $this->determineSeverity($error);
        $category = $this->determineCategory($error);
        $priority = $this->calculatePriority($severity, $category);

        return new ErrorClassification($severity, $category, $priority);
    }

    private function determineSeverity(ErrorRecord $error): string 
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($error)) {
                return $rule->getSeverity();
            }
        }

        return 'medium';
    }

    private function determineCategory(ErrorRecord $error): string 
    {
        foreach ($this->patterns as $category => $pattern) {
            if (preg_match($pattern, $error->getMessage())) {
                return $category;
            }
        }

        return 'uncategorized';
    }

    private function calculatePriority(string $severity, string $category): int 
    {
        $severityScores = [
            'critical' => 5,
            'high' => 4,
            'medium' => 3,
            'low' => 2,
            'info' => 1
        ];

        $categoryScores = [
            'security' => 5,
            'data_loss' => 4,
            'performance' => 3,
            'functionality' => 2,
            'ui' => 1
        ];

        $severityScore = $severityScores[$severity] ?? 3;
        $categoryScore = $categoryScores[$category] ?? 2;

        return min(5, ceil(($severityScore + $categoryScore) / 2));
    }
}

class SimilarityDetector {
    private float $threshold;
    private array $algorithms;

    public function findSimilar(ErrorRecord $error): array 
    {
        $similar = [];
        $recentErrors = $this->getRecentErrors();

        foreach ($recentErrors as $recentError) {
            $similarity = $this->calculateSimilarity($error, $recentError);
            if ($similarity >= $this->threshold) {
                $similar[] = $recentError;
            }
        }

        return $similar;
    }

    private function calculateSimilarity(ErrorRecord $error1, ErrorRecord $error2): float 
    {
        $scores = [];

        foreach ($this->algorithms as $algorithm) {
            $scores[] = $algorithm->compare($error1, $error2);
        }

        return array_sum($scores) / count($scores);
    }
}

class ErrorGrouper {
    private GroupRepository $repository;
    private GroupingStrategy $strategy;

    public function groupWith(ErrorRecord $error, array $similarErrors): ErrorGroup 
    {
        $existingGroup = $this->findExistingGroup($similarErrors);
        
        if ($existingGroup) {
            $existingGroup->addError($error);
            $this->repository->update($existingGroup);
            return $existingGroup;
        }

        $newGroup = $this->createGroup($error, $similarErrors);
        $this->repository->save($newGroup);
        return $newGroup;
    }

    private function findExistingGroup(array $similarErrors): ?ErrorGroup 
    {
        $groups = array_map(
            fn($error) => $error->getGroupId(),
            $similarErrors
        );

        $groups = array_filter($groups);
        if (empty($groups)) {
            return null;
        }

        $mostFrequent = array_count_values($groups);
        arsort($mostFrequent);
        $groupId = key($mostFrequent);

        return $this->repository->find($groupId);
    }

    private function createGroup(ErrorRecord $error, array $similarErrors): ErrorGroup 
    {
        return new ErrorGroup(
            $error,
            $this->strategy->determineGroupType($error, $similarErrors)
        );
    }
}

