<?php

namespace App\Core\Monitoring\UserActivity\Behavior;

class BehavioralPatternDetector
{
    private PatternRepository $patternRepository;
    private UserProfileManager $profileManager;
    private MLModelManager $mlManager;
    private SequenceAnalyzer $sequenceAnalyzer;
    private TimeWindowAnalyzer $timeWindowAnalyzer;
    private Cache $cache;

    public function __construct(
        PatternRepository $patternRepository,
        UserProfileManager $profileManager,
        MLModelManager $mlManager,
        SequenceAnalyzer $sequenceAnalyzer,
        TimeWindowAnalyzer $timeWindowAnalyzer,
        Cache $cache
    ) {
        $this->patternRepository = $patternRepository;
        $this->profileManager = $profileManager;
        $this->mlManager = $mlManager;
        $this->sequenceAnalyzer = $sequenceAnalyzer;
        $this->timeWindowAnalyzer = $timeWindowAnalyzer;
        $this->cache = $cache;
    }

    public function detectPatterns(UserActivity $activity): BehaviorAnalysis
    {
        $cacheKey = $this->generateCacheKey($activity);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        try {
            // Start detection transaction
            $transaction = DB::beginTransaction();

            // Get user profile and historical patterns
            $userProfile = $this->profileManager->getProfile($activity->getUserId());
            $historicalPatterns = $this->getHistoricalPatterns($userProfile);

            // Analyze current activity sequence
            $sequencePatterns = $this->sequenceAnalyzer->analyze(
                $activity,
                $userProfile->getRecentActivities()
            );

            // Analyze time-based patterns
            $timePatterns = $this->timeWindowAnalyzer->analyze(
                $activity,
                $userProfile->getTimeWindows()
            );

            // Generate ML predictions
            $predictions = $this->mlManager->predict([
                'activity' => $activity->toArray(),
                'historical_patterns' => $historicalPatterns,
                'sequence_patterns' => $sequencePatterns,
                'time_patterns' => $timePatterns
            ]);

            // Create behavior analysis
            $analysis = new BehaviorAnalysis(
                $sequencePatterns,
                $timePatterns,
                $predictions,
                $this->calculateRiskScore($sequencePatterns, $timePatterns, $predictions)
            );

            // Cache results
            $this->cache->set($cacheKey, $analysis, $this->getCacheDuration());

            // Update user profile
            $this->updateUserProfile($userProfile, $analysis);

            // Commit transaction
            $transaction->commit();

            return $analysis;

        } catch (\Exception $e) {
            $transaction->rollback();
            throw new BehaviorDetectionException(
                "Failed to detect behavioral patterns: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function getHistoricalPatterns(UserProfile $profile): array
    {
        return $this->patternRepository->findByUser(
            $profile->getUserId(),
            $this->getHistoricalTimeframe()
        );
    }

    private function calculateRiskScore(
        array $sequencePatterns,
        array $timePatterns,
        MLPredictions $predictions
    ): float {
        return (
            $this->calculateSequenceRisk($sequencePatterns) * 0.4 +
            $this->calculateTimePatternRisk($timePatterns) * 0.3 +
            $predictions->getRiskScore() * 0.3
        );
    }

    private function updateUserProfile(UserProfile $profile, BehaviorAnalysis $analysis): void
    {
        $profile->addBehaviorAnalysis($analysis);
        $this->profileManager->updateProfile($profile);
    }
}

class SequenceAnalyzer
{
    private PatternMatcher $matcher;
    private AnomalyDetector $anomalyDetector;
    private SequenceValidator $validator;

    public function analyze(UserActivity $activity, array $recentActivities): array
    {
        // Validate activity sequence
        $validatedSequence = $this->validator->validate(
            array_merge($recentActivities, [$activity])
        );

        // Match against known patterns
        $matches = $this->matcher->findMatches($validatedSequence);

        // Detect anomalies in sequence
        $anomalies = $this->anomalyDetector->detectAnomalies($validatedSequence);

        return array_merge(
            $this->processMatches($matches),
            $this->processAnomalies($anomalies)
        );
    }

    private function processMatches(array $matches): array
    {
        return array_map(function($match) {
            return new SequencePattern(
                $match->getPattern(),
                $match->getConfidence(),
                $match->getOccurrences()
            );
        }, $matches);
    }

    private function processAnomalies(array $anomalies): array
    {
        return array_map(function($anomaly) {
            return new AnomalyPattern(
                $anomaly->getType(),
                $anomaly->getSeverity(),
                $anomaly->getContext()
            );
        }, $anomalies);
    }
}

class TimeWindowAnalyzer
{
    private TimeFrameManager $timeFrameManager;
    private FrequencyAnalyzer $frequencyAnalyzer;
    private TemporalPatternDetector $temporalDetector;

    public function analyze(UserActivity $activity, array $timeWindows): array
    {
        // Get relevant time frames
        $timeFrames = $this->timeFrameManager->getRelevantFrames($activity);

        // Analyze activity frequency
        $frequencies = $this->frequencyAnalyzer->analyze($activity, $timeFrames);

        // Detect temporal patterns
        $temporalPatterns = $this->temporalDetector->detect($activity, $timeFrames);

        return $this->combineTimeBasedPatterns(
            $frequencies,
            $temporalPatterns
        );
    }

    private function combineTimeBasedPatterns(array $frequencies, array $temporalPatterns): array
    {
        $patterns = [];

        foreach ($frequencies as $frequency) {
            $patterns[] = new TimeBasedPattern(
                PatternType::FREQUENCY,
                $frequency->getValue(),
                $frequency->getTimeFrame()
            );
        }

        foreach ($temporalPatterns as $pattern) {
            $patterns[] = new TimeBasedPattern(
                PatternType::TEMPORAL,
                $pattern->getValue(),
                $pattern->getTimeFrame()
            );
        }

        return $patterns;
    }
}

class BehaviorAnalysis
{
    private array $sequencePatterns;
    private array $timePatterns;
    private MLPredictions $predictions;
    private float $riskScore;
    private array $metadata;

    public function __construct(
        array $sequencePatterns,
        array $timePatterns,
        MLPredictions $predictions,
        float $riskScore
    ) {
        $this->sequencePatterns = $sequencePatterns;
        $this->timePatterns = $timePatterns;
        $this->predictions = $predictions;
        $this->riskScore = $riskScore;
        $this->metadata = [
            'timestamp' => microtime(true),
            'version' => '1.0',
            'confidence_score' => $this->calculateConfidence()
        ];
    }

    public function hasAnomalies(): bool
    {
        return $this->hasSequenceAnomalies() || 
               $this->hasTimeAnomalies() || 
               $this->predictions->hasAnomalies();
    }

    public function getRiskScore(): float
    {
        return $this->riskScore;
    }

    private function hasSequenceAnomalies(): bool
    {
        return !empty(array_filter(
            $this->sequencePatterns,
            fn($pattern) => $pattern instanceof AnomalyPattern
        ));
    }

    private function hasTimeAnomalies(): bool
    {
        return !empty(array_filter(
            $this->timePatterns,
            fn($pattern) => $pattern->isAnomalous()
        ));
    }

    private function calculateConfidence(): float
    {
        return (
            $this->getSequencePatternsConfidence() * 0.4 +
            $this->getTimePatternConfidence() * 0.3 +
            $this->predictions->getConfidence() * 0.3
        );
    }
}
