<?php

namespace App\Core\Logging\PatternRecognition;

class PatternRecognizer implements PatternRecognizerInterface
{
    private PatternRepository $repository;
    private MachineLearningEngine $mlEngine;
    private PatternMatcher $matcher;
    private MetricsCollector $metrics;
    private Config $config;

    public function __construct(
        PatternRepository $repository,
        MachineLearningEngine $mlEngine,
        PatternMatcher $matcher,
        MetricsCollector $metrics,
        Config $config
    ) {
        $this->repository = $repository;
        $this->mlEngine = $mlEngine;
        $this->matcher = $matcher;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function recognize(LogEntry $entry): PatternAnalysis
    {
        $startTime = microtime(true);

        try {
            // Extract features from the log entry
            $features = $this->extractFeatures($entry);

            // Find known patterns
            $knownPatterns = $this->findKnownPatterns($features);

            // Discover new patterns
            $newPatterns = $this->discoverNewPatterns($features, $knownPatterns);

            // Combine and analyze patterns
            $analysis = $this->analyzePatterns($knownPatterns, $newPatterns, $entry);

            // Update pattern database
            $this->updatePatternDatabase($analysis);

            // Record metrics
            $this->recordMetrics($analysis, microtime(true) - $startTime);

            return $analysis;

        } catch (\Exception $e) {
            $this->handleRecognitionError($entry, $e);
            throw $e;
        }
    }

    protected function extractFeatures(LogEntry $entry): FeatureSet
    {
        return new FeatureSet([
            'textual' => $this->extractTextualFeatures($entry),
            'temporal' => $this->extractTemporalFeatures($entry),
            'contextual' => $this->extractContextualFeatures($entry),
            'structural' => $this->extractStructuralFeatures($entry)
        ]);
    }

    protected function extractTextualFeatures(LogEntry $entry): array
    {
        return [
            'tokens' => $this->tokenizeMessage($entry->message),
            'ngrams' => $this->generateNgrams($entry->message),
            'keywords' => $this->extractKeywords($entry->message),
            'entities' => $this->extractEntities($entry->message)
        ];
    }

    protected function extractTemporalFeatures(LogEntry $entry): array
    {
        return [
            'timestamp' => $entry->timestamp,
            'hour' => $entry->timestamp->hour,
            'day' => $entry->timestamp->day,
            'month' => $entry->timestamp->month,
            'dayOfWeek' => $entry->timestamp->dayOfWeek,
            'timeZone' => $entry->timestamp->timezone
        ];
    }

    protected function extractContextualFeatures(LogEntry $entry): array
    {
        return [
            'level' => $entry->level,
            'type' => $entry->type,
            'source' => $entry->source,
            'environment' => $entry->environment,
            'tags' => $entry->tags,
            'metadata' => $entry->metadata
        ];
    }

    protected function extractStructuralFeatures(LogEntry $entry): array
    {
        return [
            'format' => $this->detectFormat($entry->message),
            'structure' => $this->analyzeStructure($entry->message),
            'components' => $this->identifyComponents($entry->message),
            'patterns' => $this->findStructuralPatterns($entry->message)
        ];
    }

    protected function findKnownPatterns(FeatureSet $features): array
    {
        $patterns = [];
        $knownPatterns = $this->repository->getActivePatterns();

        foreach ($knownPatterns as $pattern) {
            $match = $this->matcher->match($features, $pattern);
            if ($match->confidence >= $this->config->get('patterns.min_confidence', 0.8)) {
                $patterns[] = [
                    'pattern' => $pattern,
                    'match' => $match,
                    'confidence' => $match->confidence
                ];
            }
        }

        return $patterns;
    }

    protected function discoverNewPatterns(FeatureSet $features, array $knownPatterns): array
    {
        // Skip if too many patterns already match
        if (count($knownPatterns) >= $this->config->get('patterns.max_matches', 5)) {
            return [];
        }

        // Use ML to discover new patterns
        $predictions = $this->mlEngine->predict($features);

        return array_filter($predictions, function ($prediction) {
            return $prediction->confidence >= $this->config->get('patterns.discovery_threshold', 0.9);
        });
    }

    protected function analyzePatterns(array $knownPatterns, array $newPatterns, LogEntry $entry): PatternAnalysis
    {
        $analysis = new PatternAnalysis([
            'entry_id' => $entry->id,
            'known_patterns' => $knownPatterns,
            'new_patterns' => $newPatterns,
            'timestamp' => now()
        ]);

        // Calculate significances
        $analysis->calculateSignificances([
            'frequency' => $this->calculateFrequencySignificance($knownPatterns),
            'novelty' => $this->calculateNoveltySignificance($newPatterns),
            'impact' => $this->calculateImpactSignificance($knownPatterns, $newPatterns)
        ]);

        // Identify correlations
        $analysis->setCorrelations(
            $this->findPatternCorrelations($knownPatterns, $newPatterns)
        );

        // Generate insights
        $analysis->setInsights(
            $this->generatePatternInsights($analysis)
        );

        return $analysis;
    }

    protected function updatePatternDatabase(PatternAnalysis $analysis): void
    {
        DB::transaction(function () use ($analysis) {
            // Update known pattern statistics
            foreach ($analysis->getKnownPatterns() as $pattern) {
                $this->repository->updatePatternStats($pattern);
            }

            // Store new patterns if they meet criteria
            foreach ($analysis->getNewPatterns() as $pattern) {
                if ($this->shouldStorePattern($pattern)) {
                    $this->repository->storePattern($pattern);
                }
            }

            // Update correlations
            $this->repository->updateCorrelations($analysis->getCorrelations());

            // Cleanup obsolete patterns
            if ($this->shouldPerformCleanup()) {
                $this->cleanupPatterns();
            }
        });
    }

    protected function shouldStorePattern(Pattern $pattern): bool
    {
        return $pattern->confidence >= $this->config->get('patterns.storage_threshold', 0.95) &&
               $pattern->significance >= $this->config->get('patterns.significance_threshold', 0.8) &&
               !$this->repository->hasSimularPattern($pattern);
    }

    protected function recordMetrics(PatternAnalysis $analysis, float $duration): void
    {
        $this->metrics->increment('pattern_recognition.processed');
        $this->metrics->gauge('pattern_recognition.known_patterns', count($analysis->getKnownPatterns()));
        $this->metrics->gauge('pattern_recognition.new_patterns', count($analysis->getNewPatterns()));
        $this->metrics->timing('pattern_recognition.duration', $duration);

        foreach ($analysis->getSignificances() as $type => $value) {
            $this->metrics->gauge("pattern_recognition.significance.{$type}", $value);
        }
    }

    protected function handleRecognitionError(LogEntry $entry, \Exception $e): void
    {
        // Log error
        Log::error('Pattern recognition failed', [
            'entry_id' => $entry->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Update metrics
        $this->metrics->increment('pattern_recognition.errors');

        // Notify if critical
        if ($this->isCriticalError($e)) {
            $this->notifyError($entry, $e);
        }
    }

    protected function shouldPerformCleanup(): bool
    {
        return rand(1, 100) <= $this->config->get('patterns.cleanup_probability', 5);
    }

    protected function cleanupPatterns(): void
    {
        $threshold = now()->subDays(
            $this->config->get('patterns.obsolescence_days', 30)
        );

        $this->repository->deleteObsoletePatterns($threshold);
    }
}
