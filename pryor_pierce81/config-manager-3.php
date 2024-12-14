<?php

namespace App\Core\Config;

class ConfigurationManager implements ConfigurationInterface 
{
    private ConfigStore $store;
    private ValidationEngine $validator;
    private EncryptionService $encryption;
    private ConfigurationLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        ConfigStore $store,
        ValidationEngine $validator,
        EncryptionService $encryption,
        ConfigurationLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->store = $store;
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function validateConfiguration(ConfigContext $context): ConfigResult
    {
        $validationId = $this->initializeValidation($context);
        
        try {
            DB::beginTransaction();

            $config = $this->loadConfiguration($context);
            $this->validateConfigStructure($config);
            $this->validateSecurityParameters($config);
            $this->validateDependencies($config);

            $encryptedConfig = $this->encryptSensitiveData($config);
            $this->verifyEncryption($encryptedConfig);

            $result = new ConfigResult([
                'validationId' => $validationId,
                'config' => $encryptedConfig,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (ConfigurationException $e) {
            DB::rollBack();
            $this->handleConfigFailure($e, $validationId);
            throw new CriticalConfigurationException($e->getMessage(), $e);
        }
    }

    private function validateConfigStructure(Configuration $config): void
    {
        $violations = $this->validator->validateStructure($config);
        
        if (!empty($violations)) {
            $this->emergency->handleConfigViolations($violations);
            throw new ConfigStructureException(
                'Configuration structure validation failed',
                ['violations' => $violations]
            );
        }
    }

    private function validateSecurityParameters(Configuration $config): void
    {
        if (!$this->validator->validateSecurity($config)) {
            $this->emergency->handleSecurityValidationFailure($config);
            throw new SecurityParameterException('Security parameters validation failed');
        }
    }

    private function encryptSensitiveData(Configuration $config): Configuration
    {
        return $this->encryption->encryptConfig($config, [
            'algorithm' => 'AES-256-GCM',
            'key_rotation' => true
        ]);
    }

    private function handleConfigFailure(ConfigurationException $e, string $validationId): void
    {
        $this->logger->logFailure($e, $validationId);
        
        if ($e->isCritical()) {
            $this->emergency->escalateToHighestLevel();
            $this->alerts->dispatchCriticalAlert(
                new ConfigurationFailureAlert($e, $validationId)
            );
        }
    }
}
