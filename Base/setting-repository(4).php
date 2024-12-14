<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\SettingRepositoryInterface;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class SettingRepository implements SettingRepositoryInterface
{
    /**
     * @var Setting
     */
    protected Setting $model;

    /**
     * Constructor
     *
     * @param Setting $model
     */
    public function __construct(Setting $model)
    {
        $this->model = $model;
    }

    /**
     * @inheritDoc
     */
    public function get(string $key, $default = null)
    {
        $setting = $this->model->where('key', $key)->first();
        
        if (!$setting) {
            return $default;
        }

        return $this->decodeValue($setting->value);
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, $value): bool
    {
        try {
            $this->model->updateOrCreate(
                ['key' => $key],
                ['value' => $this->encodeValue($value)]
            );
            return true;
        } catch (QueryException $e) {
            report($e);
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return $this->model->where('key', $key)->exists();
    }

    /**
     * @inheritDoc
     */
    public function remove(string $key): bool
    {
        try {
            return (bool) $this->model->where('key', $key)->delete();
        } catch (QueryException $e) {
            report($e);
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getAllByGroup(string $group): Collection
    {
        return $this->model
            ->where('key', 'LIKE', $group . '%')
            ->get()
            ->mapWithKeys(function ($setting) {
                return [$setting->key => $this->decodeValue($setting->value)];
            });
    }

    /**
     * @inheritDoc
     */
    public function getAll(): Collection
    {
        return $this->model
            ->all()
            ->mapWithKeys(function ($setting) {
                return [$setting->key => $this->decodeValue($setting->value)];
            });
    }

    /**
     * @inheritDoc
     */
    public function setMany(array $settings): bool
    {
        try {
            DB::beginTransaction();

            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }

            DB::commit();
            return true;
        } catch (QueryException $e) {
            DB::rollBack();
            report($e);
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function removeMany(array $keys): bool
    {
        try {
            DB::beginTransaction();

            $result = $this->model->whereIn('key', $keys)->delete();

            DB::commit();
            return (bool) $result;
        } catch (QueryException $e) {
            DB::rollBack();
            report($e);
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function removeByGroup(string $group): bool
    {
        try {
            DB::beginTransaction();

            $result = $this->model->where('key', 'LIKE', $group . '%')->delete();

            DB::commit();
            return (bool) $result;
        } catch (QueryException $e) {
            DB::rollBack();
            report($e);
            return false;
        }
    }

    /**
     * Encode value for storage
     *
     * @param mixed $value
     * @return string
     */
    protected function encodeValue($value): string
    {
        return json_encode($value);
    }

    /**
     * Decode value from storage
     *
     * @param string $value
     * @return mixed
     */
    protected function decodeValue(string $value)
    {
        return json_decode($value, true);
    }
}
