<?php

namespace App\Core\User\Profile;

class UserProfileManagementSystem
{
    private ProfileRepository $repository;
    private BehaviorAnalyzer $behaviorAnalyzer;
    private PreferenceManager $preferenceManager;
    private SecurityManager $securityManager;
    private ProfileCache $cache;
    private ProfileValidator $validator;

    public function __construct(
        ProfileRepository $repository,
        BehaviorAnalyzer $behaviorAnalyzer,
        PreferenceManager $preferenceManager,
        SecurityManager $securityManager,
        ProfileCache $cache,
        ProfileValidator $validator
    ) {
        $this->repository = $repository;
        $this->behaviorAnalyzer = $behaviorAnalyzer;
        $this->preferenceManager = $preferenceManager;
        $this->securityManager = $securityManager;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function getProfile(string $userId): UserProfile
    {
        $cacheKey = $this->generateCacheKey($userId);
        
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        try {
            // Start profile retrieval transaction
            $transaction = DB::beginTransaction();

            // Get base profile
            $profile = $this->repository->find($userId);

            if (!$profile) {
                $profile = $this->createDefaultProfile($userId);
            }

            // Analyze recent behavior
            $behavior = $this->behaviorAnalyzer->analyze($userId);

            // Get user preferences
            $preferences = $this->preferenceManager->getPreferences($userId);

            // Create enhanced profile
            $enhancedProfile = new EnhancedUserProfile(
                $profile,
                $behavior,
                $preferences,
                $this->generateMetadata($userId)
            );

            // Validate profile
            $this->validator->validate($enhancedProfile);

            // Cache profile
            $this->cache->set($cacheKey, $enhancedProfile);

            // Commit transaction
            $transaction->commit();

            return $enhancedProfile;

        } catch (\Exception $e) {
            $transaction->rollback();
            throw new ProfileException(
                "Failed to retrieve user profile: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function updateProfile(string $userId, array $updates): UserProfile
    {
        try {
            // Start update transaction
            $transaction = DB::beginTransaction();

            // Get current profile
            $currentProfile = $this->getProfile($userId);

            // Validate updates
            $validatedUpdates = $this->validator->validateUpdates($updates);

            // Apply updates
            $updatedProfile = $currentProfile->applyUpdates($validatedUpdates);

            // Verify security implications
            $this->securityManager->verifyProfileUpdates($currentProfile, $updatedProfile);

            // Save updates
            $this->repository->save($updatedProfile);

            // Invalidate cache
            $this->cache->invalidate($this->generateCacheKey($userId));

            // Commit transaction
            $transaction->commit();

            return $updatedProfile;

        } catch (\Exception $e) {
            $transaction->rollback();
            throw new ProfileUpdateException(
                "Failed to update user profile: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function createDefaultProfile(string $userId): UserProfile
    {
        return new UserProfile([
            'user_id' => $userId,
            'created_at' => time(),
            'status' => ProfileStatus::ACTIVE,
            'settings' => $this->getDefaultSettings(),
            'preferences' => $this->getDefaultPreferences()
        ]);
    }
}

class BehaviorAnalyzer
{
    private ActivityRepository $activityRepository;
    private PatternDetector $patternDetector;
    private RiskAssessor $riskAssessor;
    private TimeframeManager $timeframeManager;

    public function analyze(string $userId): BehaviorProfile
    {
        // Get relevant timeframe
        $timeframe = $this->timeframeManager->getAnalysisTimeframe();

        // Get user activities
        $activities = $this->activityRepository->getActivities($userId, $timeframe);

        // Detect patterns
        $patterns = $this->patternDetector->detectPatterns($activities);

        // Assess risks
        $risks = $this->riskAssessor->assess($activities, $patterns);

        return new BehaviorProfile(
            $patterns,
            $risks,
            $this->calculateBehaviorMetrics($activities),
            $timeframe
        );
    }

    private function calculateBehaviorMetrics(array $activities): array
    {
        return [
            'activity_frequency' => $this->calculateFrequency($activities),
            'peak_activity_times' => $this->findPeakTimes($activities),
            'common_patterns' => $this->findCommonPatterns($activities),
            'risk_indicators' => $this->identifyRiskIndicators($activities)
        ];
    }
}

class PreferenceManager
{
    private PreferenceRepository $repository;
    private PreferenceValidator $validator;
    private DefaultsProvider $defaultsProvider;

    public function getPreferences(string $userId): UserPreferences
    {
        // Get stored preferences
        $stored = $this->repository->findByUser($userId);

        // Merge with defaults
        $merged = $this->mergeWithDefaults($stored);

        // Validate preferences
        $validated = $this->validator->validate($merged);

        return new UserPreferences($validated);
    }

    public function updatePreferences(string $userId, array $preferences): UserPreferences
    {
        // Validate new preferences
        $validated = $this->validator->validate($preferences);

        // Get current preferences
        $current = $this->getPreferences($userId);

        // Merge preferences
        $merged = $current->merge($validated);

        // Save updated preferences
        $this->repository->save($userId, $merged);

        return new UserPreferences($merged);
    }

    private function mergeWithDefaults(array $stored): array
    {
        $defaults = $this->defaultsProvider->getDefaults();
        return array_merge($defaults, $stored);
    }
}

class UserProfile
{
    private string $userId;
    private array $data;
    private BehaviorProfile $behavior;
    private UserPreferences $preferences;
    private array $metadata;
    private \DateTime $lastUpdated;

    public function applyUpdates(array $updates): self
    {
        $updated = clone $this;
        
        foreach ($updates as $key => $value) {
            if ($this->isUpdateAllowed($key)) {
                $updated->data[$key] = $value;
            }
        }

        $updated->lastUpdated = new \DateTime();
        $updated->metadata['update_count']++;

        return $updated;
    }

    public function getBehaviorProfile(): BehaviorProfile
    {
        return $this->behavior;
    }

    public function getPreferences(): UserPreferences
    {
        return $this->preferences;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    private function isUpdateAllowed(string $key): bool
    {
        return in_array($key, $this->getAllowedUpdateFields());
    }

    private function getAllowedUpdateFields(): array
    {
        return [
            'display_name',
            'email',
            'notification_settings',
            'privacy_settings',
            'ui_preferences'
        ];
    }
}

class BehaviorProfile
{
    private array $patterns;
    private array $risks;
    private array $metrics;
    private TimeFrame $timeframe;
    private float $lastUpdated;

    public function __construct(
        array $patterns,
        array $risks,
        array $metrics,
        TimeFrame $timeframe
    ) {
        $this->patterns = $patterns;
        $this->risks = $risks;
        $this->metrics = $metrics;
        $this->timeframe = $timeframe;
        $this->lastUpdated = microtime(true);
    }

    public function getPatterns(): array
    {
        return $this->patterns;
    }

    public function getRisks(): array
    {
        return $this->risks;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getTimeframe(): TimeFrame
    {
        return $this->timeframe;
    }

    public function needsUpdate(): bool
    {
        return (microtime(true) - $this->lastUpdated) > self::UPDATE_THRESHOLD;
    }
}

class UserPreferences
{
    private array $preferences;
    private array $defaults;
    private ValidationRules $rules;

    public function merge(array $newPreferences): array
    {
        $merged = array_merge($this->preferences, $newPreferences);
        return $this->validate($merged);
    }

    public function get(string $key, $default = null)
    {
        return $this->preferences[$key] ?? $this->defaults[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        if ($this->isValidPreference($key, $value)) {
            $this->preferences[$key] = $value;
        }
    }

    private function isValidPreference(string $key, $value): bool
    {
        return isset($this->rules[$key]) && 
               $this->rules[$key]->validate($value);
    }

    private function validate(array $preferences): array
    {
        $validated = [];
        foreach ($preferences as $key => $value) {
            if ($this->isValidPreference($key, $value)) {
                $validated[$key] = $value;
            }
        }
        return $validated;
    }
}
