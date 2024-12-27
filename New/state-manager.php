<?php

namespace App\Core\State;

use App\Core\Security\SecurityContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class StateManager implements StateInterface
{
    private MetricsCollector $metrics;
    private ValidationService $validator;
    private BackupManager $backup;

    public function __construct(
        MetricsCollector $metrics,
        ValidationService $validator,
        BackupManager $backup
    ) {
        $this->metrics = $metrics;
        $this->validator = $validator;
        $this->backup = $backup;
    }

    public function captureState(): string
    {
        $stateId = $this->generateStateId();
        
        try {
            $state = $this->captureSystemState();
            $this->validateStateData($state);
            $this->storeState($stateId, $state);
            
            return $stateId;
            
        } catch (\Exception $e) {
            Log::error('State capture failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function restoreState(string $stateId): void
    {
        DB::beginTransaction();
        
        try {
            $state = $this->loadState($stateId);
            $this->validateStateData($state);
            $this->performStateRestoration($state);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('State restoration failed', [
                'state_id' => $stateId,
                'error' => $e->getMessage()
            ]);
            
            throw new StateRestorationException(
                'Failed to restore state: ' . $e->getMessage()
            );
        }
    }

    public function validateState(string $stateId): bool
    {
        try {
            $state = $this->loadState($stateId);
            return $this->validator->validateStateData($state);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function generateStateId(): string
    {
        return uniqid('state_', true);
    }

    protected function captureSystemState(): array
    {
        return [
            'memory' => $this->captureMemoryState(),
            'database' => $this->captureDatabaseState(),
            'cache' => $this->captureCacheState(),
            'files' => $this->captureFileState(),
            'timestamp' => microtime(true)
        ];
    }

    protected function captureMemoryState(): array
    {
        return [
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }

    protected function captureDatabaseState(): array
    {
        return DB::select('SHOW STATUS');
    }

    protected function captureCacheState(): array
    {
        return [
            'size' => Cache::size(),
            'keys' => Cache::keys()
        ];
    }

    protected function captureFileState(): array
    {
        return [
            'temp_files' => $this->getTempFiles(),
            'storage_usage' => disk_free_space(storage_path())
        ];
    }

    protected function validateStateData(array $state): void
    {
        if (!$this->validator->validateStateData($state)) {
            throw new InvalidStateException('Invalid state data');
        }
    }

    protected function storeState(string $stateId, array $state): void
    {
        $encrypted = encrypt($state);
        Cache::put("state:$stateId", $encrypted, now()->addHours(1));
        
        $this->backup->backupState($stateId, $encrypted);
    }

    protected function loadState(string $stateId): array
    {
        $encrypted = Cache::get("state:$stateId");
        
        if (!$encrypted) {
            $encrypted = $this->backup->loadState($stateId);
        }
        
        if (!$encrypted) {
            throw new StateNotFoundException("State not found: $stateId");
        }
        
        return decrypt($encrypted);
    }

    protected function performStateRestoration(array $state): void
    {
        $this->restoreMemoryState($state['memory']);
        $this->restoreDatabaseState($state['database']);
        $this->restoreCacheState($state['cache']);
        $this->restoreFileState($state['files']);
    }

    protected function restoreMemoryState(array $memory): void
    {
        if (memory_get_usage(true) > $memory['usage']) {
            gc_collect_cycles();
        }
    }

    protected function restoreDatabaseState(array $status): void
    {
        foreach ($status as $variable) {
            DB::statement("SET GLOBAL {$variable->Variable_name} = ?", [
                $variable->Value
            ]);
        }
    }

    protected function restoreCacheState(array $cache): void
    {
        Cache::flush();
        
        foreach ($cache['keys'] as $key) {
            Cache::put($key, Cache::get($key));
        }
    }

    protected function restoreFileState(array $files): void
    {
        foreach ($files['temp_files'] as $file) {
            if (!file_exists($file)) {
                copy($this->backup->getBackupPath($file), $file);
            }
        }
    }

    protected function getTempFiles(): array
    {
        $path = storage_path('temp');
        return glob("$path/*");
    }
}

interface StateInterface
{
    public function captureState(): string;
    public function restoreState(string $stateId): void;
    public function validateState(string $stateId): bool;
}

class InvalidStateException extends \Exception {}
class StateNotFoundException extends \Exception {}
class StateRestorationException extends \Exception {}
