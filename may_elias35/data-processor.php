<?php

namespace App\Core\Audit;

class DataProcessor
{
    private DataCleaner $cleaner;
    private DataNormalizer $normalizer;
    private DataTransformer $transformer;
    private DataValidator $validator;
    private ProcessingConfig $config;

    public function __construct(
        DataCleaner $cleaner,
        DataNormalizer $normalizer,
        DataTransformer $transformer,
        DataValidator $validator
    ) {
        $this->cleaner = $cleaner;
        $this->normalizer = $normalizer;
        $this->transformer = $transformer;
        $this->validator = $validator;
    }

    public function clean(array $data): self
    {
        try {
            $this->data = $this->cleaner
                ->removeDuplicates($data)
                ->handleMissingValues()
                ->removeOutliers()
                ->standardizeFormats()
                ->getData();
            
            return $this;
        } catch (\Exception $e) {
            throw new DataProcessingException(
                "Data cleaning failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function normalize(): self 
    {
        try {
            $this->data = $this->normalizer
                ->scaleNumericalValues($this->data)
                ->normalizeCategories()
                ->standardizeTimestamps()
                ->normalizeTextData()
                ->getData();
            
            return $this;
        } catch (\Exception $e) {
            throw new DataProcessingException(
                "Data normalization failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function transform(): self
    {
        try {
            $this->data = $this->transformer
                ->applyTransformations($this->data, $this->config->getTransformations())
                ->encodeCategories()
                ->generateFeatures()
                ->restructureData()
                ->getData();
            
            return $this;
        } catch (\Exception $e) {
            throw new DataProcessingException(
                "Data transformation failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function validate(): self
    {
        $validationResult = $this->validator->validate($this->data);
        
        if (!$validationResult->isValid()) {
            throw new DataValidationException(
                "Data validation failed: " . implode(", ", $validationResult->getErrors())
            );
        }
        
        return $this;
    }

    public function process(): ProcessedData
    {
        return new ProcessedData([
            'data' => $this->data,
            'metadata' => [
                'processing_steps' => $this->getProcessingSteps(),
                'transformations_applied' => $this->getAppliedTransformations(),
                'validation_rules' => $this->getValidationRules(),
                'processing_timestamp' => now()
            ]
        ]);
    }

    public function setConfig(ProcessingConfig $config): self
    {
        $this->config = $config;
        return $this;
    }

    protected function getProcessingSteps(): array
    {
        return [
            'cleaning' => $this->cleaner->getAppliedSteps(),
            'normalization' => $this->normalizer->getAppliedSteps(),
            'transformation' => $this->transformer->getAppliedSteps(),
            'validation' => $this->validator->getAppliedRules()
        ];
    }
}
