<?php

namespace App\Repositories;

use App\Models\Template;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class TemplateRepository extends BaseRepository
{
    public function __construct(Template $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findActive(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('status', 'active')
                             ->orderBy('name')
                             ->get();
        });
    }

    public function findByType(string $type): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$type], function () use ($type) {
            return $this->model->where('type', $type)
                             ->where('status', 'active')
                             ->get();
        });
    }

    public function findDefault(): ?Template
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('is_default', true)
                             ->where('status', 'active')
                             ->first();
        });
    }

    public function setDefault(int $id): bool
    {
        $this->model->where('is_default', true)->update(['is_default' => false]);
        $result = $this->update($id, ['is_default' => true]);
        $this->clearCache();
        return $result;
    }

    public function duplicate(int $id): ?Template
    {
        $template = $this->find($id);
        if (!$template) {
            return null;
        }

        $copy = $this->create([
            'name' => $template->name . ' (Copy)',
            'type' => $template->type,
            'content' => $template->content,
            'status' => 'draft'
        ]);

        $this->clearCache();
        return $copy;
    }
}
