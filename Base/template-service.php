<?php

namespace App\Core\Services;

use App\Core\Models\Template;
use App\Core\Services\Contracts\TemplateServiceInterface;
use App\Core\Repositories\Contracts\TemplateRepositoryInterface;
use App\Core\Exceptions\TemplateNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class TemplateService implements TemplateServiceInterface
{
    public function __construct(
        private TemplateRepositoryInterface $repository
    ) {}

    public function getTemplate(string $slug): Template
    {
        return Cache::tags(['templates'])->remember(
            "template.{$slug}",
            now()->addHour(),
            fn() => $this->repository->findBySlug($slug)
        );
    }

    public function createTemplate(array $data): Template
    {
        $template = $this->repository->store($data);
        Cache::tags(['templates'])->flush();
        return $template;
    }

    public function updateTemplate(int $id, array $data): Template
    {
        $template = $this->repository->update($id, $data);
        Cache::tags(['templates'])->flush();
        return $template;
    }

    public function deleteTemplate(int $id): bool
    {
        $result = $this->repository->delete($id);
        Cache::tags(['templates'])->flush();
        return $result;
    }

    public function renderTemplate(string $slug, array $data = []): string
    {
        $template = $this->getTemplate($slug);
        return $template->compile($data);
    }

    public function getDefaultTemplate(): Template
    {
        return Cache::tags(['templates'])->remember(
            'template.default',
            now()->addHour(),
            function () {
                $template = $this->repository->getActive()
                    ->where('is_default', true)
                    ->first();

                if (!$template) {
                    throw new TemplateNotFoundException("No default template found");
                }

                return $template;
            }
        );
    }
}
