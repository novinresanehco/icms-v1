<?php

namespace App\Repositories;

use App\Models\Template;
use App\Repositories\Contracts\TemplateRepositoryInterface;
use Illuminate\Support\Collection;

class TemplateRepository extends BaseRepository implements TemplateRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'content'];
    protected array $filterableFields = ['type', 'status'];

    public function __construct(Template $model)
    {
        parent::__construct($model);
    }

    public function compile(int $templateId, array $data = []): ?string
    {
        try {
            $template = $this->find($templateId);
            if (!$template) {
                throw new \Exception('Template not found');
            }

            $blade = app('blade.compiler');
            return $blade->compileString($template->content, $data);
        } catch (\Exception $e) {
            Log::error('Failed to compile template: ' . $e->getMessage());
            return null;
        }
    }

    public function getByType(string $type): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("type.{$type}"),
                $this->cacheTTL,
                fn() => $this->model->where('type', $type)
                    ->where('status', 'active')
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get templates by type: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function createVersion(int $templateId): bool
    {
        try {
            DB::beginTransaction();

            $template = $this->find($templateId);
            if (!$template) {
                throw new \Exception('Template not found');
            }

            $template->versions()->create([
                'content' => $template->content,
                'created_by' => auth()->id()
            ]);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create template version: ' . $e->getMessage());
            return false;
        }
    }
}
