<?php

namespace App\Repositories;

use App\Models\Language;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class LanguageRepository extends BaseRepository
{
    public function __construct(Language $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findActive(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('active', true)
                             ->orderBy('name')
                             ->get();
        });
    }

    public function findByCode(string $code): ?Language
    {
        return $this->executeWithCache(__FUNCTION__, [$code], function () use ($code) {
            return $this->model->where('code', $code)->first();
        });
    }

    public function updateStatus(int $id, bool $active): bool
    {
        $result = $this->update($id, ['active' => $active]);
        $this->clearCache();
        return $result;
    }

    public function setDefault(int $id): bool
    {
        $this->model->where('is_default', true)
                    ->update(['is_default' => false]);
                    
        $result = $this->update($id, ['is_default' => true]);
        $this->clearCache();
        return $result;
    }

    public function findDefault(): ?Language
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('is_default', true)->first();
        });
    }
}
