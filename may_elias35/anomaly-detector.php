<?php

namespace App\Core\Audit;

class AnomalyDetector
{
    private AnomalyConfig $config;
    private array $statisticalAnomalies = [];
    private array $patternAnomalies = [];
    private array $contextualAnomalies = [];
    private AnomalyCalculator $calculator;
    private array $data;

    public function setConfig(AnomalyConfig $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function detect(ProcessedData $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function detectStatisticalAnomalies(): self
    {
        try {
            $this->statisticalAnomalies = [
                'outliers' => $this->detectOutliers(),
                'distribution_anomalies' => $this->detectDistributionAnomalies(),
                'relationship_anomalies' => $this->detectRelationshipAnomalies(),
                'trend_anomalies' => $this->detectTrendAnomalies()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new AnomalyDetectionException(
                "Statistical anomaly detection failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function detectPatternAnomalies(): self
    {
        try {
            $this->patternAnomalies = [
                'sequence_anomalies' => $this->detectSequenceAnomalies(),
                'behavioral_anomalies' => $this->detectBehavioralAnomalies(),
                'temporal_anomalies' => $this->detectTemporalAnomalies(),
                'structural_anomalies' => $this->detectStructuralAnomalies()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new AnomalyDetectionException(
                "Pattern anomaly detection failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function detectContextualAnomalies(): self
    {
        try {
            $this->contextualAnomalies = [
                'contextual_outliers' => $this->detectContextualOutliers(),
                'seasonal_anomalies' => $this->detectSeasonalAnomalies(),
                'event_anomalies' => $this->detectEventAnomalies(),
                'relationship_context_anomalies' => $this->detectRelationshipContextAnomalies()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new AnomalyDetectionException(
                "Contextual anomaly detection failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function getResults(): array
    {
        return [
            'statistical_anomalies' => $this->statisticalAnomalies,
            'pattern_anomalies' => $this->patternAnomalies,
            'contextual_anomalies' => $this->contextualAnomalies,
            'metadata' => [
                'config' => $this->config,
                'timestamp' => now(),
                'anomaly_counts' => $this->getAnomalyCounts()
            ]
        ];
    }

    protected function detectOutliers(): array
    {
        return $this->calculator->detectOutliers(
            $this->data,
            $this->config->getOutlierConfig()
        );
    }

    protected function detectDistributionAnomalies(): array
    {
        return $this->calculator->detectDistributionAnomalies(
            $this->data,
            $this->config->getDistributionConfig()
        );
    }

    protected function detectContextualOutliers(): array
    {
        return $this->calculator->detectContextualOutliers(
            $this->data,
            $this->config->getContextConfig()
        );
    }

    protected function getAnomalyCounts(): array
    {
        return [
            'statistical' => array_sum(array_map('count', $this->statisticalAnomalies)),
            'pattern' => array_sum(array_map('count', $this->patternAnomalies)),
            'contextual' => array_sum(array_map('count', $this->contextualAnomalies))
        ];
    }
}
