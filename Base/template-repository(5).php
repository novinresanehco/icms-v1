<?php

namespace App\Repositories;

use App\Models\Template;
use App\Models\TemplateVersion;
use App\Repositories\Contracts\TemplateRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TemplateRepository extends BaseRepository implements TemplateRepositoryInterface
{
    protected function getModel(): Model
    {
        return new Template();
    }

    public function findBySlug(string $slug): ?Template
    {
        return $this->model->where('slug', $slug)->first();
    }

    public function findWithComponents(int $id): ?Template
    {
        return $this->model->with(['components', 'assets', 'settings'])->find($id);
    }

    public function getActiveTemplates(): Collection
    {
        return $this->model->where('status', 'active')
            ->with(['components', 'assets'])
            ->get();
    }

    public function createWithComponents(array $data, array $components): Template
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        
        $template = $this->model->create($data);
        $template->components()->createMany($components);
        
        $this->createVersion($template);
        
        return $template->load('components');
    }

    public function updateWithComponents(int $id, array $data, array $components): bool
    {
        $template = $this->model->findOrFail($id);
        
        if (isset($data['name']) && (!isset($data['slug']) || empty($data['slug']))) {
            $data['slug'] = Str::slug($data['name']);
        }
        
        $updated = $template->update($data);
        
        if ($updated) {
            $template->components()->delete();
            $template->components()->createMany($components);
            $this->createVersion($template);
        }
        
        return $updated;
    }

    public function getTemplatesByType(string $type): Collection
    {
        return $this->model->where('type', $type)
            ->with(['components', 'assets'])
            ->get();
    }

    public function getTemplateVersions(int $id): Collection
    {
        return TemplateVersion::where('template_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function activateTemplate(int $id): bool
    {
        return $this->model->findOrFail($id)->update(['status' => 'active']);
    }

    public function deactivateTemplate(int $id): bool
    {
        return $this->model->findOrFail($id)->update(['status' => 'inactive']);
    }

    public function duplicateTemplate(int $id, string $newName): Template
    {
        $original = $this->findWithComponents($id);
        
        if (!$original) {
            throw new \RuntimeException("Template not found");
        }
        
        $newData = $original->toArray();
        $newData['name'] = $newName;
        $newData['slug'] = Str::slug($newName);
        $newData['status'] = 'inactive';
        
        unset($newData['id'], $newData['created_at'], $newData['updated_at']);
        
        $components = $original->components->map(function ($component) {
            $data = $component->toArray();
            unset($data['id'], $data['template_id'], $data['created_at'], $data['updated_at']);
            return $data;
        })->toArray();
        
        return $this->createWithComponents($newData, $components);
    }

    protected function createVersion(Template $template): void
    {
        TemplateVersion::create([
            'template_id' => $template->id,
            'name' => $template->name,
            'content' => json_encode($template->components),
            'created_by' => auth()->id()
        ]);
    }
}
