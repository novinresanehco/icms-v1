namespace App\Core\Input\Quality;

class QualityScorer
{
    private array $scorers = [];
    private WeightManager $weightManager;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(
        WeightManager $weightManager,
        MetricsCollector $metrics,
        LoggerInterface $logger
    ) {
        $this->weightManager = $weightManager;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function registerScorer(QualityScorer $scorer, float $weight = 1.0): void
    {
        $this->scorers[get_class($scorer)] = [
            'scorer' => $scorer,
            'weight' => $weight
        ];
    }

    public function scoreInput(mixed $input, array $context = []): QualityScore
    {
        $startTime = microtime(true);
        
        try {
            $dimensions = [];
            $totalScore = 0;
            $totalWeight = 0;

            foreach ($this->scorers as $scorerClass => $config) {
                $scorer = $config['scorer'];
                $weight = $this->weightManager->getWeight($scorerClass, $config['weight']);

                if (!$scorer->supports($input)) {
                    continue;
                }

                $dimensionScore = $scorer->score($input, $context);
                $weightedScore = $dimensionScore->getScore() * $weight;

                $dimensions[$scorer->getDimension()] = new ScoringDimension(
                    name: $scorer->getDimension(),
                    score: $dimensionScore->getScore(),
                    weight: $weight,
                    weightedScore: $weightedScore,
                    metrics: $dimensionScore->getMetrics(),
                    suggestions: $dimensionScore->getSuggestions()
                );

                $totalScore += $weightedScore;
                $totalWeight += $weight;
            }

            $finalScore = $totalWeight > 0 ? $totalScore / $totalWeight : 0;

            $result = new QualityScore(
                score: $finalScore,
                dimensions: $dimensions,
                duration: microtime(true) - $startTime,
                metadata: [
                    'input_type' => gettype($input),
                    'scorer_count' => count($this->scorers),
                    'timestamp' => time()
                ]
            );

            $this->recordMetrics($result);
            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Quality scoring failed', [
                'error' => $e->getMessage(),
                'input_type' => gettype($input)
            ]);
            throw new ScoringException(
                "Failed to calculate quality score: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function recordMetrics(QualityScore $score): void
    {
        $this->metrics->record([
            'overall_score' => $score->getScore(),
            'processing_time' => $score->getDuration(),
            'dimensions_count' => count($score->getDimensions())
        ]);
    }
}

abstract class BaseScorer implements QualityScorer
{
    protected LoggerInterface $logger;
    protected array $config;

    abstract public function getDimension(): string;
    abstract protected function calculateScore(mixed $input, array $context): float;
    abstract protected function generateMetrics(mixed $input): array;
    abstract protected function generateSuggestions(float $score, array $metrics): array;

    public function score(mixed $input, array $context = []): DimensionScore
    {
        $score = $this->calculateScore($input, $context);
        $metrics = $this->generateMetrics($input);
        $suggestions = $this->generateSuggestions($score, $metrics);

        return new DimensionScore(
            score: $score,
            metrics: $metrics,
            suggestions: $suggestions
        );
    }
}

class ComplexityScorer extends BaseScorer
{
    public function getDimension(): string
    {
        return 'complexity';
    }

    public function supports(mixed $input): bool
    {
        return is_string($input) || is_array($input);
    }

    protected function calculateScore(mixed $input, array $context): float
    {
        if (is_string($input)) {
            return $this->calculateStringComplexity($input);
        }
        return $this->calculateArrayComplexity($input);
    }

    protected function generateMetrics(mixed $input): array
    {
        if (is_string($input)) {
            return [
                'length' => strlen($input),
                'word_count' => str_word_count($input),
                'unique_chars' => count(array_unique(str_split($input)))
            ];
        }
        return [
            'depth' => $this->calculateArrayDepth($input),
            'total_elements' => count($input, COUNT_RECURSIVE),
            'unique_keys' => count(array_unique(array_keys($input)))
        ];
    }

    protected function generateSuggestions(float $score, array $metrics): array
    {
        $suggestions = [];

        if ($score < 0.5) {
            $suggestions[] = 'Consider simplifying the input structure';
        }
        if ($metrics['depth'] ?? 0 > 3) {
            $suggestions[] = 'Deep nesting detected. Consider flattening the structure';
        }

        return $suggestions;
    }

    private function calculateStringComplexity(string $input): float
    {
        $length = strlen($input);
        $uniqueChars = count(array_unique(str_split($input)));
        $wordCount = str_word_count($input);

        return min(1.0, ($uniqueChars / $length) * ($wordCount / 100));
    }

    private function calculateArrayComplexity(array $input): float
    {
        $depth = $this->calculateArrayDepth($input);
        $totalElements = count($input, COUNT_RECURSIVE);
        
        return min(1.0, 1 - (($depth * $totalElements) / 1000));
    }

    private function calculateArrayDepth(array $array): int
    {
        $maxDepth = 1;
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->calculateArrayDepth($value) + 1;
                $maxDepth = max($maxDepth, $depth);
            }
        }
        return $maxDepth;
    }
}

class QualityScorer extends BaseScorer
{
    private array $patterns;
    private array $qualityRules;

    public function getDimension(): string
    {
        return 'quality';
    }

    public function supports(mixed $input): bool
    {
        return is_string($input);
    }

    protected function calculateScore(mixed $input, array $context): float
    {
        $patternScore = $this->evaluatePatterns($input);
        $ruleScore = $this->evaluateRules($input);
        
        return ($patternScore + $ruleScore) / 2;
    }

    protected function generateMetrics(mixed $input): array
    {
        return [
            'pattern_matches' => $this->countPatternMatches($input),
            'rule_compliance' => $this->calculateRuleCompliance($input),
            'quality_indicators' => $this->identifyQualityIndicators($input)
        ];
    }

    protected function generateSuggestions(float $score, array $metrics): array
    {
        $suggestions = [];

        foreach ($this->qualityRules as $rule) {
            if (!$rule->isSatisfied($metrics)) {
                $suggestions[] = $rule->getSuggestion();
            }
        }

        return $suggestions;
    }
}

// Value Objects
class QualityScore
{
    public function __construct(
        private float $score,
        private array $dimensions,
        private float $duration,
        private array $metadata
    ) {}

    public function getScore(): float
    {
        return $this->score;
    }

    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class DimensionScore
{
    public function __construct(
        private float $score,
        private array $metrics,
        private array $suggestions
    ) {}

    public function getScore(): float
    {
        return $this->score;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
}

class ScoringDimension
{
    public function __construct(
        private string $name,
        private float $score,
        private float $weight,
        private float $weightedScore,
        private array $metrics,
        private array $suggestions
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getWeightedScore(): float
    {
        return $this->weightedScore;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
}
