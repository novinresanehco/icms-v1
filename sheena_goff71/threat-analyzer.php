<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ThreatAnalyzer 
{
    private const THREAT_CACHE_KEY = 'current_threat_level';
    private const THREAT_HISTORY_KEY = 'threat_history';
    
    private AuditLogger $auditLogger;
    private array $thresholds;
    private array $weights;

    public function __construct(AuditLogger $auditLogger)
    {
        $this->auditLogger = $auditLogger;
        $this->thresholds = config('security.threat_thresholds');
        $this->weights = config('security.threat_weights');
    }

    public function getCurrentThreatLevel(): int 
    {
        return Cache::remember(self::THREAT_CACHE_KEY, now()->addMinutes(5), function() {
            return $this->calculateThreatLevel();
        });
    }

    public function recordThreatEvent(SecurityException $e): void 
    {
        DB::transaction(function() use ($e) {
            // Log threat event
            $eventId = $this->logThreatEvent($e);
            
            // Update threat metrics
            $this->updateThreatMetrics($eventId, $e);
            
            // Clear threat level cache to force recalculation
            Cache::forget(self::THREAT_CACHE_KEY);
            
            // Record in threat history
            $this->updateThreatHistory($e);
            
            // Trigger alerts if needed
            $this->checkThreatAlerts($e);
        });
    }

    public function analyzePattern(string $pattern): array
    {
        return [
            'frequency' => $this->calculatePatternFrequency($pattern),
            'severity' => $this->assessPatternSeverity($pattern),
            'correlation' => $this->findPatternCorrelations($pattern),
            'risk_level' => $this->calculatePatternRisk($pattern)
        ];
    }

    private function calculateThreatLevel(): int
    {
        $score = 0;
        
        // Check recent security violations
        $score += $this->getSecurityViolationScore();
        
        // Check access patterns
        $score += $this->getAccessPatternScore();
        
        // Check system anomalies
        $score += $this->getSystemAnomalyScore();
        
        // Check attack indicators
        $score += $this->getAttackIndicatorScore();
        
        return $this->normalizeThreatScore($score);
    }

    private function getSecurityViolationScore(): int
    {
        $violations = DB::table('security_events')
            ->where('created_at', '>=', now()->subHours(1))
            ->count();
            
        return $violations * $this->weights['security_violations'];
    }

    private function getAccessPatternScore(): int
    {
        $suspiciousPatterns = DB::table('access_log')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->where('suspicious', true)
            ->count();
            
        return $suspiciousPatterns * $this->weights['suspicious_access'];
    }

    private function getSystemAnomalyScore(): int
    {
        $anomalies = DB::table('system_anomalies')
            ->where('created_at', '>=', now()->subHours(1))
            ->where('severity', '>=', $this->thresholds['anomaly_severity'])
            ->count();
            
        return $anomalies * $this->weights['system_anomalies'];
    }

    private function getAttackIndicatorScore(): int
    {
        $indicators = DB::table('attack_indicators')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->where('confidence', '>=', $this->thresholds['indicator_confidence'])
            ->count();
            
        return $indicators * $this->weights['attack_indicators'];
    }

    private function logThreatEvent(SecurityException $e): string
    {
        return DB::table('threat_events')->insertGetId([
            'type' => $e->getCode(),
            'severity' => $e->getSeverity(),
            'details' => json_encode([
                'message' => $e->getMessage(),
                'context' => $e->getContext(),
                'stack_trace' => $e->getTraceAsString()
            ]),
            'created_at' => now()
        ]);
    }

    private function updateThreatMetrics(string $eventId, SecurityException $e): void
    {
        DB::table('threat_metrics')->insert([
            'event_id' => $eventId,
            'metric_type' => $e->getType(),
            'metric_value' => $e->getSeverity(),
            'created_at' => now()
        ]);
    }

    private function updateThreatHistory(SecurityException $e): void
    {
        $history = Cache::get(self::THREAT_HISTORY_KEY, []);
        
        $history[] = [
            'timestamp' => now()->timestamp,
            'type' => $e->getCode(),
            'severity' => $e->getSeverity(),
            'threat_level' => $this->getCurrentThreatLevel()
        ];
        
        // Keep last 100 events
        $history = array_slice($history, -100);
        
        Cache::put(self::THREAT_HISTORY_KEY, $history, now()->addDay());
    }

    private function checkThreatAlerts(SecurityException $e): void
    {
        $currentLevel = $this->getCurrentThreatLevel();
        
        if ($currentLevel >= $this->thresholds['critical_threat']) {
            $this->auditLogger->logCriticalThreat([
                'threat_level' => $currentLevel,
                'trigger_event' => $e->getMessage(),
                'context' => $e->getContext()
            ]);
        }
    }

    private function calculatePatternFrequency(string $pattern): int
    {
        return DB::table('security_events')
            ->where('pattern', $pattern)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    private function assessPatternSeverity(string $pattern): int
    {
        return DB::table('security_events')
            ->where('pattern', $pattern)
            ->where('created_at', '>=', now()->subDay())
            ->avg('severity') ?? 0;
    }

    private function findPatternCorrelations(string $pattern): array
    {
        return DB::table('security_events')
            ->select('correlated_pattern', DB::raw('count(*) as frequency'))
            ->where('pattern', $pattern)
            ->where('created_at', '>=', now()->subDay())
            ->groupBy('correlated_pattern')
            ->orderByDesc('frequency')
            ->limit(5)
            ->get()
            ->toArray();
    }

    private function calculatePatternRisk(string $pattern): int
    {
        $frequency = $this->calculatePatternFrequency($pattern);
        $severity = $this->assessPatternSeverity($pattern);
        
        return min(100, ($frequency * $severity) / 100);
    }

    private function normalizeThreatScore(int $score): int
    {
        return min(100, max(0, $score));
    }
}
