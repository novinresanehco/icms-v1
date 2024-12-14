<?php

namespace App\Core\Audit;

class TrendAnalyzer
{
    private TrendConfig $config;
    private array $seasonality = [];
    private array $cycles = [];
    private array $forecasts = [];
    private TrendCalculator $calculator;

    public function setConfig(TrendConfig $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function analyze(ProcessedData $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function detectSeasonality(): self
    {
        try {
            $this->seasonality = [
                'seasonal_components' => $this->findSeasonalComponents(),
                'seasonal_strength' => $this->calculateSeasonalStrength(),
                'seasonal_peaks' => $this->identifySeasonalPeaks(),
                'seasonal_decomposition' => $this->performSeasonalDecomposition()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new TrendAnalysisException(
                "Seasonality detection failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function detectCycles(): self
    {
        try {
            $this->cycles = [
                'cycle_components' => $this->findCycleComponents(),
                'cycle_periods' => $this->calculateCyclePeriods(),
                'cycle_strength' => $this->calculateCycleStrength(),
                'cycle_phases' => $this->identifyCyclePhases()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new TrendAnalysisException(
                "Cycle detection failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function forecastTrends(): self
    {
        try {
            $this->forecasts = [
                'short_term' => $this->generateShortTermForecast(),
                'medium_term' => $this->generateMediumTermForecast(),
                'long_term' => $this->generateLongTermForecast(),
                'forecast_confidence' => $this->calculateForecastConfidence()
            ];
            return $this;
        } catch (\Exception $e) {
            throw new TrendAnalysisException(
                "Trend forecasting failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function getResults(): array
    {
        return [
            'seasonality' => $this->seasonality,
            'cycles' => $this->cycles,
            'forecasts' => $this->forecasts,
            'metadata' => [
                'config' => $this->config,
                'timestamp' => now(),
                'data_range' => $this->getDataRange()
            ]
        ];
    }

    protected function findSeasonalComponents(): array
    {
        return $this->calculator->findSeasonalComponents(
            $this->data,
            $this->config->getSeasonalityConfig()
        );
    }

    protected function calculateSeasonalStrength(): array
    {
        return $this->calculator->calculateSeasonalStrength(
            $this->seasonality['seasonal_components'],
            $this->config->getStrengthThreshold()
        );
    }

    protected function findCycleComponents(): array
    {
        return $this->calculator->findCycleComponents(
            $this->data,
            $this->config->getCycleConfig()
        );
    }

    protected function generateShortTermForecast(): array
    {
        return $this->calculator->generateForecast(
            $this->data,
            $this->config->getShortTermConfig()
        );
    }

    protected function getDataRange(): array
    {
        return [
            'start' => min(array_column($this->data, 'timestamp')),
            'end' => max(array_column($this->data, 'timestamp')),
            'duration' => $this->calculator->calculateDuration($this->data)
        ];
    }
}
