<?php

namespace App\Core\Config;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;

class ConfigurationManager implements ConfigurationManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $loadedConfigurations = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function loadConfiguration(string $name): array
    {
        $configId = $this->generateConfigId();

        try {
            $this->security->validateSecureOperation('config:load', [
                'config_name' => $name
            ]);

            $this->validateConfigName($name);
            $this->validateConfigAccess($name);

            $configuration = $this->loadConfigurationData($name);
            $this->validateConfiguration($configuration, $name);

            $this->loadedConfigurations[$name] = $configuration;
            $this->logConfigurationLoad($configId, $name);

            return $configuration;

        } catch (\Exception $e) {
            $this->handleConfigurationFailure($configId, $name, 'load', $e);
            throw new ConfigurationException('Configuration load failed', 0, $e);
        }
    }

    public function updateConfiguration(string $name, array $data): void
    {
        $configId = $this->generateConfigId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('config:update', [
                'config_name' => $name
            ]);

            $this->validateConfigName($name);
            $this->validateConfigData($data);
            $this->validateConfigurationUpdate($name, $data);

            $this->processConfigurationUpdate($name, $data);
            $this->logConfigurationUpdate($configId, $name);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleConfigurationFailure($configId, $name, 'update', $e);
            throw new ConfigurationException('Configuration update failed', 0, $e);
        }
    }

    private function validateConfigName(string $name): void
    {
        if (!preg_match($this->config['name_pattern'], $name)) {
            throw new ConfigurationException('Invalid configuration name');
        }

        if (!isset($this->config['allowed_configs'][$name])) {
            throw new ConfigurationException('Unknown configuration');
        }
    }

    private function validateConfiguration(array $configuration, string $name): void
    {
        $validator = $this->getConfigValidator($name);
        
        if (!$validator->validate($configuration)) {
            throw new ConfigurationException('Invalid configuration structure');
        }

        if (!$this->validateSecurityConstraints($configuration)) {
            throw new ConfigurationException('Configuration security validation failed');
        }
    }

    private function loadConfigurationData(string $name): array
    {
        $path = $this->getConfigPath($name);
        
        if (!file_exists($path)) {
            throw new ConfigurationException('Configuration file not found');
        }

        $data = require $path;
        return $this->processConfigurationData($data);
    }

    private function handleConfigurationFailure(string $id, string $name, string $operation, \Exception $e): void
    {
        $this->logger->error('Configuration operation failed', [
            'config_id' => $id,
            'config_name' => $name,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->notifyConfigurationFailure($id, $name, $operation, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'name_pattern' => '/^[a-z0-9_-]+$/',
            'allowed_configs' => [
                'app' => true,
                'security' => true,
                'database' => true,
                'cache' => true,
                'logging' => true
            ],
            'config_path' => config_path(),
            'strict_mode' => true,
            'cache_enabled' => true
        ];
    }
}
