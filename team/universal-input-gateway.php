namespace App\Core\Input\Gateway;

class UniversalInputGateway
{
    private InputSecurityScanner $securityScanner;
    private InputNormalizer $normalizer;
    private CrossLanguageHandler $languageHandler;
    private QualityScorer $qualityScorer;
    private TransformationPipeline $pipeline;
    private InputVersionControl $versionControl;
    private RealTimeAnalyticsEngine $analytics;
    private CacheManager $cache;
    private LoggerInterface $logger;

    public function __construct(
        InputSecurityScanner $securityScanner,
        InputNormalizer $normalizer,
        CrossLanguageHandler $languageHandler,
        QualityScorer $qualityScorer,
        TransformationPipeline $pipeline,
        InputVersionControl $versionControl,
        RealTimeAnalyticsEngine $analytics,
        CacheManager $cache,
        LoggerInterface $logger
    ) {
        $this->securityScanner = $securityScanner;
        $this->normalizer = $normalizer;
        $this->languageHandler = $languageHandler;
        $this->qualityScorer = $qualityScorer;
        $this->pipeline = $pipeline;
        $this->versionControl = $versionControl;
        $this->analytics = $analytics;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function processInput(mixed $input, array $options = []): ProcessedInput
    {
        $context = $this->createContext($input, $options);
        $startTime = microtime(true);

        try {
            // Security scan
            $securityResult = $this->securityScanner->scanInput($input, $context);
            if (!$securityResult->isValid()) {
                throw new SecurityViolationException($securityResult->getViolations());
            }

            // Language processing
            $languageResult = $this->languageHandler->processInput($input, $context);

            // Normalization
            $normalizedInput = $this->normalizer->normalize($languageResult->getNormalizedInput());

            // Quality scoring
            $qualityScore = $this->qualityScorer->scoreInput($normalizedInput, $context);

            // Transform input
            $transformedInput = $this->pipeline->transform($normalizedInput);

            // Create version
            $version = $this->versionControl->createVersion(
                new InputData($transformedInput->getOutput()),
                ['quality_score' => $qualityScore->getScore()]
            );

            // Process analytics
            $this->analytics->processInput($transformedInput->getOutput(), $context);

            $result = new ProcessedInput(
                input: $input,
                output: $transformedInput->getOutput(),
                securityResult: $securityResult,
                languageResult: $languageResult,
                qualityScore: $qualityScore,
                transformationResult: $transformedInput,
                version: $version,
                processingTime: microtime(true) - $startTime
            );

            $this->cache->set($this->getCacheKey($input), $result);
            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Input processing failed', [
                'error' => $e->getMessage(),
                'input_type' => gettype($input),
                'context' => $context
            ]);
            throw new InputProcessingException(
                "Failed to process input: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function createContext(mixed $input, array $options): array
    {
        return [
            'timestamp' => time(),
            'input_type' => gettype($input),
            'options' => $options,
            'session_id' => session_id(),
            'user_id' => auth()->id()
        ];
    }

    private function getCacheKey(mixed $input): string
    {
        return 'input_gateway:' . md5(serialize($input));
    }
}

class ProcessedInput
{
    public function __construct(
        private mixed $input,
        private mixed $output,
        private SecurityScanResult $securityResult,
        private LanguageResult $languageResult,
        private QualityScore $qualityScore,
        private TransformationResult $transformationResult,
        private Version $version,
        private float $processingTime
    ) {}

    public function getInput(): mixed
    {
        return $this->input;
    }

    public function getOutput(): mixed
    {
        return $this->output;
    }

    public function getSecurityResult(): SecurityScanResult
    {
        return $this->securityResult;
    }

    public function getLanguageResult(): LanguageResult
    {
        return $this->languageResult;
    }

    public function getQualityScore(): QualityScore
    {
        return $this->qualityScore;
    }

    public function getTransformationResult(): TransformationResult
    {
        return $this->transformationResult;
    }

    public function getVersion(): Version
    {
        return $this->version;
    }

    public function getProcessingTime(): float
    {
        return $this->processingTime;
    }
}

class InputProcessingException extends \RuntimeException {}
class SecurityViolationException extends \RuntimeException {}
