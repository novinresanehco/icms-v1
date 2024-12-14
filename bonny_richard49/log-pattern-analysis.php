<?php

namespace App\Core\Logging\PatternRecognition;

class PatternAnalysis
{
    private string $entryId;
    private array $knownPatterns;
    private array $newPatterns;
    private array $significances = [];
    private array $correlations = [];
    private array $insights = [];
    private Carbon $timestamp;

    public function __construct(array $data)
    {
        $this->entryId = $data['entry_id'];
        $this->knownPatterns = $data['known_patterns'];
        $this->newPatterns = $data['new_patterns'];
        $this->timestamp = $data['timestamp'];
    }

    public function calculateSignificances(array $factors): void
    {
        $this->significances = array_merge(
            $this->significances,
            $factors
        );

        // Calculate overall significance
        $this->significances['overall'] = $this->calculateOverallSignificance($factors);
    }

    public function setCorrelations(array $correlations): void
    {
        $this->correlations = $correlations;
    }

    public function setInsights(array $insights): void
    {
        $this->insights = $insights;
    }

    public function getKnownPatterns(): array
    {
        return $this->knownPatterns;
    }

    public function getNewPatterns(): array
    {
        return $this->newPatterns;
    }

    public function getSignificances(): array
    {
        return $this->significances;
    }

    public function getCorrelations(): array
    {
        return $this->correlations;
    }

    public function getInsights(): array
    {
        return $this->insights;
    }

    public function hasSignificantPatterns(): bool
    {
        return $this->significances['overall'] >= 0.8;
    }

    public function getHighestSignificancePattern(): ?Pattern
    {
        return collect(array_merge($this->knownPatterns, $this->newPatterns))
            ->sortByDesc('significance')
            ->first();
    }

    public function toArray(): array
    {
        return [
            'entry_id' => $this->entryId,
            'known_patterns' => $this->knownPatterns,
            'new_patterns' => $this->newPatterns,
            'significances' => $this->significances,
            'correlations' => $this->correlations,
            'insights' => $this->insights,
            'timestamp' => $this->timestamp->toIso8601String()
        ];
    }

    protected function calculateOverallSignificance(array $factors): float
    {
        return collect($factors)
            ->average();
    }
}
