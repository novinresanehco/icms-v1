<?php

namespace App\Repositories;

use App\Models\EmailTemplate;
use App\Repositories\Contracts\EmailTemplateRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EmailTemplateRepository extends BaseRepository implements EmailTemplateRepositoryInterface
{
    protected array $searchableFields = ['name', 'subject', 'description'];
    protected array $filterableFields = ['type', 'status'];

    public function findByCode(string $code): ?EmailTemplate
    {
        return Cache::tags(['email_templates'])->remember("email_template.{$code}", 3600, function() use ($code) {
            return $this->model
                ->where('code', $code)
                ->where('status', 'active')
                ->first();
        });
    }

    public function renderTemplate(EmailTemplate $template, array $data): string
    {
        try {
            $renderer = app('App\Services\Email\TemplateRenderer');
            return $renderer->render($template, $data);
        } catch (\Exception $e) {
            \Log::error('Error rendering email template: ' . $e->getMessage());
            throw $e;
        }
    }

    public function duplicateTemplate(int $id): ?EmailTemplate
    {
        try {
            $template = $this->findById($id);
            $clone = $template->replicate();
            $clone->name = $template->name . ' (Copy)';
            $clone->code = $template->code . '_copy_' . time();
            $clone->status = 'draft';
            $clone->save();
            
            return $clone;
        } catch (\Exception $e) {
            \Log::error('Error duplicating email template: ' . $e->getMessage());
            return null;
        }
    }

    public function getVariables(EmailTemplate $template): array
    {
        try {
            preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template->content, $matches);
            return array_unique($matches[1]);
        } catch (\Exception $e) {
            \Log::error('Error extracting email template variables: ' . $e->getMessage());
            return [];
        }
    }
}
