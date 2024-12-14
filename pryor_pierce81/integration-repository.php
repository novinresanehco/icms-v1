<?php

namespace App\Core\Repository;

use App\Models\Integration;
use App\Core\Events\IntegrationEvents;
use App\Core\Exceptions\IntegrationRepositoryException;

class IntegrationRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Integration::class;
    }

    /**
     * Register new integration
     */
    public function registerIntegration(string $type, array $config): Integration
    {
        try {
            DB::beginTransaction();

            // Validate configuration
            $this->validateIntegrationConfig($type, $config);

            // Create integration record
            $integration = $this->create([
                'type' => $type,
                'name' => $config['name'],
                'config' => $this->encryptSensitiveData($config),
                'status' => 'pending',
                'created_by' => auth()->id()
            ]);

            // Test connection
            $this->testConnection($integration);

            // Update status
            $integration->update(['status' => 'active']);

            DB::commit();
            event(new IntegrationEvents\IntegrationRegistered($integration));

            return $integration;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new IntegrationRepositoryException(
                "Failed to register integration: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update integration configuration
     */
    public function updateConfig(int $id, array $config): Integration
    {
        try {
            $integration = $this->find($id);
            if (!$integration) {
                throw new IntegrationRepositoryException("Integration not found with ID: {$id}");
            }

            // Validate new configuration
            $this->validateIntegrationConfig($integration->type, $config);

            // Update configuration
            $integration->update([
                'config' => $this->encryptSensitiveData($config),
                'last_updated' => now()
            ]);

            // Test connection with new config
            $this->testConnection($integration);

            $this->clearCache();
            event(new IntegrationEvents\IntegrationConfigUpdated($integration));

            return $integration;

        } catch (\Exception $e) {
            throw new IntegrationRepositoryException(
                "Failed to update integration config: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get active integrations by type
     */
    public function getActiveIntegrations(string $type): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("active.{$type}"),
            $this->cacheTime,
            fn() => $this->model->where('type', $type)
                               ->where('status', 'active')
                               ->get()
        );
    }

    /**
     * Log integration activity
     */
    public function logActivity(int $integrationId, string $action, array $data = []): void
    {
        try {
            DB::table('integration_logs')->insert([
                'integration_id' => $integrationId,
                'action' => $action,
                'data' => json_encode($data),
                'created_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to log integration activity: {$e->getMessage()}", [
                'integration_id' => $integrationId,
                'action' => $action
            ]);
        }
    }

    /**
     * Get integration activity logs
     */
    public function getActivityLogs(int $integrationId, array $options = []): Collection
    {
        $query = DB::table('integration_logs')
            ->where('integration_id', $integrationId);

        if (isset($options['from'])) {
            $query->where('created_at', '>=', $options['from']);
        }

        if (isset($options['action'])) {
            $query->where('action', $options['action']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Test integration connection
     */
    protected function testConnection(Integration $integration): bool
    {
        // Implementation depends on integration type
        $tester = $this->getConnectionTester($integration->type);
        return $tester->test($integration->config);
    }

    /**
     * Encrypt sensitive configuration data
     */
    protected function encryptSensitiveData(array $config): array
    {
        $sensitiveFields = ['api_key', 'secret', 'password', 'token'];
        
        foreach ($config as $key => $value) {
            if (in_array($key, $sensitiveFields)) {
                $config[$key] = encrypt($value);
            }
        }

        return $config;
    }
}
