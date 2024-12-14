<?php

namespace App\Core\Data;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\DataException;
use App\Core\Validation\ValidationManagerInterface;
use Psr\Log\LoggerInterface;

class DataManager implements DataManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationManagerInterface $validator;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationManagerInterface $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function processData(array $data, string $schema): array
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('data:process', [
                'schema' => $schema
            ]);

            $this->validateDataSchema($data, $schema);
            $this->validateDataIntegrity($data);

            $processedData = $this->executeDataProcessing($data, $schema);
            $this->validateProcessedData($processedData, $schema);

            $this->logDataOperation($operationId, 'process', $schema);

            DB::commit();
            return $processedData;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleDataFailure($operationId, 'process', $e);
            throw new DataException('Data processing failed', 0, $e);
        }
    }

    public function validateData(array $data, string $schema): bool
    {
        $validationId = $this->generateValidationId();

        try {
            $this->security->validateSecureOperation('data:validate', [
                'schema' => $schema
            ]);

            $this->validateDataStructure($data, $schema);
            $this->validateDataValues($data, $schema);
            $this->validateDataRelations($data, $schema);

            $this->logDataValidation($validationId, $schema);

            return true;

        } catch (\Exception $e) {
            $this->handleValidationFailure($validationId, $schema, $e);
            throw new DataException('Data validation failed', 0, $e);
        }
    }

    public function transformData(array $data, string $format): array
    {
        $transformId = $this->generateTransformId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('data:transform', [
                'format' => $format
            ]);

            $this->validateTransformFormat($format);
            $this->validateTransformData($data);

            $transformedData = $this->executeTransformation($data, $format);
            $this->validateTransformedData($transformedData, $format);

            $this->logDataTransformation($transformId, $format);

            DB::commit();
            return $transformedData;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleTransformFailure($transformId, $format, $e);
            throw new DataException('Data transformation failed', 0, $e);
        }
    }

    private function validateDataSchema(array $data, string $schema): void
    {
        $schemaRules = $this->loadSchemaRules($schema);

        foreach ($schemaRules as $field => $rules) {
            if (!isset($data[$field]) && $rules['required']) {
                throw new DataException("Missing required field: {$field}");
            }

            if (isset($data[$field])) {
                $this->validateFieldValue($data[$field], $rules);
            }
        }
    }

    private function executeDataProcessing(array $data, string $schema): array
    {
        $processor = $this->getDataProcessor($schema);
        return $processor->process($data);
    }

    private function validateProcessedData(array $data, string $schema): void
    {
        $validator = $this->getDataValidator($schema);
        
        if (!$validator->validate($data)) {
            throw new DataException('Processed data validation failed');
        }
    }

    private function handleDataFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('Data operation failed', [
            'operation_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->notifyDataFailure($id, $operation, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'schemas' => [
                'user' => UserSchema::class,
                'content' => ContentSchema::class,
                'media' => MediaSchema::class
            ],
            'processors' => [
                'user' => UserProcessor::class,
                'content' => ContentProcessor::class,
                'media' => MediaProcessor::class
            ],
            'max_data_size' => 10485760,
            'validation_timeout' => 30
        ];
    }
}
