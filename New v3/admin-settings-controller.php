<?php

namespace App\Http\Controllers\Admin;

use App\Core\System\{
    ConfigManager,
    EnvironmentManager,
    BackupManager
};
use App\Core\Cache\CacheManager;
use App\Core\Security\AuditLogger;

class AdminSettingsController extends Controller
{
    private ConfigManager $config;
    private EnvironmentManager $env;
    private BackupManager $backup;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function updateConfig(UpdateConfigRequest $request): JsonResponse
    {
        $this->authorize('updateConfig');
        
        try {
            DB::beginTransaction();

            // Backup current config
            $this->backup->backupConfig();

            // Update configuration
            $this->config->update(
                $request->validated(),
                $request->user()
            );

            // Validate new config
            $this->validateNewConfig();

            // Clear relevant caches
            $this->cache->clearConfigCache();

            $this->audit->logConfigUpdate(
                $request->validated(),
                $request->user()
            );

            DB::commit();

            return response()->json([
                'message' => 'Configuration updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restoreConfig();
            throw $e;
        }
    }

    public function updateEnvironment(UpdateEnvRequest $request): JsonResponse
    {
        $this->authorize('updateEnvironment');
        
        try {
            DB::beginTransaction();

            // Backup current env
            $this->backup->backupEnvironment();

            // Update environment
            $this->env->update(
                $request->validated(),
                $request->user()
            );

            // Validate new environment
            $this->validateNewEnvironment();

            $this->audit->logEnvironmentUpdate(
                $request->validated(),
                $request->user()
            );

            DB::commit();

            return response()->json([
                'message' => 'Environment updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restoreEnvironment();
            throw $e;
        }
    }

    public function clearCache(Request $request): JsonResponse
    {
        $this->authorize('clearCache');

        $type = $request->input('type', 'all');

        try {
            match($type) {
                'config' => $this->cache->clearConfigCache(),
                'routes' => $this->cache->clearRoutesCache(),
                'views' => $this->cache->clearViewCache(),
                'data' => $this->cache->clearDataCache(),
                default => $this->cache->clearAllCaches()
            };

            $this->audit->logCacheClear($type, $request->user());

            return response()->json([
                'message' => "Cache '{$type}' cleared successfully"
            ]);

        } catch (\Exception $e) {
            throw new CacheClearException($type, $e);
        }
    }

    protected function validateNewConfig(): void
    {
        if (!$this->config->validate()) {
            throw new InvalidConfigException(
                'New configuration validation failed'
            );
        }
    }

    protected function validateNewEnvironment(): void
    {
        if (!$this->env->validate()) {
            throw new InvalidEnvironmentException(
                'New environment validation failed'
            );
        }
    }

    public function getBackupStatus(): JsonResponse
    {
        $this->authorize('viewBackupStatus');

        return response()->json([
            'last_backup' => $this->backup->getLastBackupInfo(),
            'backup_size' => $this->backup->getCurrentSize(),
            'backup_count' => $this->backup->getBackupCount(),
            'storage_usage' => $this->backup->getStorageUsage()
        ]);
    }
}
