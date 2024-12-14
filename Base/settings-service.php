<?php

namespace App\Core\Services;

use App\Core\Repositories\{
    SettingsRepository,
    SettingsSchemaRepository,
    SettingsHistoryRepository
};
use App\Core\Events\{SettingUpdated, SettingsGroupUpdated};
use App\Core\Exceptions\SettingsException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event, Cache};

class SettingsService extends BaseService
{
    protected SettingsSchemaRepository $schemaRepository;
    protected SettingsHistoryRepository $historyRepository;

    public function __construct(
        SettingsRepository $repository,
        SettingsSchemaRepository $schemaRepository,
        SettingsHistoryRepository $historyRepository
    ) {
        parent::__construct($repository);
        $this->schemaRepository = $schemaRepository;
        $this->historyRepository = $historyRepository;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository->get($key, $default);
    }

    public function set(string $key, mixed $value, ?string $group = null): bool
    {
        try {
            if (!$this->schemaRepository->validateValue($key, $value)) {
                throw new SettingsException("Invalid value for setting: {$key}");
            }

            DB::beginTransaction();

            $oldValue = $this->repository->get($key);
            
            $updated = $this->repository->set($key, $value, $group);
            
            if ($updated) {
                $this->historyRepository->logChange($key, $oldValue, $value);
            }

            DB::commit();

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SettingsException("Failed to update setting: {$e->getMessage()}", 0, $e);
        }
    }

    public function setGroup(string $group, array $values): bool
    {
        try {
            DB::beginTransaction();

            $schema = $this->schemaRepository->getSchema($group);
            
            if ($schema) {
                $this->validateGroupValues($values, $schema);
            }

            $updated = $this->repository->setGroup($group, $values);

            if ($updated) {
                foreach ($values as $key => $value) {
                    $oldValue = $this->repository->get($key);
                    $this->historyRepository->logChange($key, $oldValue, $value);
                }
            }

            DB::commit();

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SettingsException("Failed to update settings group: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(string $key): bool
    {
        try {
            DB::beginTransaction();

            $oldValue = $this->repository->get($key);
            
            $deleted = $this->repository->delete($key);
            
            if ($deleted) {
                $this->historyRepository->logChange($key, $oldValue, null);
            }

            DB::commit();

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SettingsException("Failed to delete setting: {$e->getMessage()}", 0, $e);
        }
    }

    public function getGroup(string $group): Collection
    {
        return $this->repository->getGroup($group);
    }

    public function getHistory(string $key): Collection
    {
        return $this->historyRepository->getHistory($key);
    }

    public function getRecentChanges(int $limit = 50): Collection
    {
        return $this->historyRepository->getRecentChanges($limit);
    }

    protected function validateGroupValues(array $values, Model $schema): void
    {
        foreach ($values as $key => $value) {
            if (!$this->schemaRepository->validateValue($key, $value)) {
                throw new SettingsException("Invalid value for setting: {$key}");
            }
        }
    }
}
