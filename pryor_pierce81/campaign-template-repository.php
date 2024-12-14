<?php

namespace App\Core\Repository;

use App\Models\CampaignTemplate;
use App\Core\Events\CampaignTemplateEvents;
use App\Core\Exceptions\CampaignTemplateException;

class CampaignTemplateRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return CampaignTemplate::class;
    }

    /**
     * Create campaign template
     */
    public function createTemplate(array $data): CampaignTemplate
    {
        try {
            DB::beginTransaction();

            // Validate template content
            $this->validateTemplateContent($data['content'] ?? [], $data['type']);

            $template = $this->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'],
                'content' => $data['content'] ?? [],
                'variables' => $data['variables'] ?? [],
                'settings' => $data['settings'] ?? [],
                'category' => $data['category'] ?? 'general',
                'status' => 'active',
                'created_by' => auth()->id()
            ]);

            DB::commit();
            event(new CampaignTemplateEvents\TemplateCreated($template));

            return $template;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new CampaignTemplateException(
                "Failed to create template: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update template content
     */
    public function updateContent(int $templateId, array $content): CampaignTemplate
    {
        try {
            $template = $this->find($templateId);
            if (!$template) {
                throw new CampaignTemplateException("Template not found with ID: {$templateId}");
            }

            // Validate new content
            $this->validateTemplateContent($content, $template->type);

            $template->update([
                'content' => $content,
                'updated_at' => now(),
                'updated_by' => auth()->id()
            ]);

            $this->clearCache();
            event(new CampaignTemplateEvents\TemplateContentUpdated($template));

            return $template;

        } catch (\Exception $e) {
            throw new CampaignTemplateException(
                "Failed to update template content: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get templates by type
     */
    public function getTemplatesByType(string $type): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("type.{$type}"),
            $this->cacheTime,
            fn() => $this->model->where('type', $type)
                               ->where('status', 'active')
                               ->orderBy('name')
                               ->get()
        );
    }

    /**
     * Render template
     */
    public function renderTemplate(int $templateId, array $data): array
    {
        try {
            $template = $this->find($templateId);
            if (!$template) {
                throw new CampaignTemplateException("Template not found with ID: {$templateId}");
            }

            // Validate required variables
            $this->validateRequiredVariables($template->variables, $data);

            $rendered = [];
            foreach ($template->content as $key => $content) {
                $rendered[$key] = $this->renderContent($content, $data);
            }

            return $rendered;

        } catch (\Exception $e) {
            throw new CampaignTemplateException(
                "Failed to render template: {$e->getMessage()}"
            );
        }
    }

    /**
     * Preview template
     */
    public function previewTemplate(int $templateId, array $data = []): array
    {
        try {
            $template = $this->find($templateId);
            if (!$template) {
                throw new CampaignTemplateException("Template not found with ID: {$templateId}");
            }

            // Generate sample data for missing variables
            $sampleData = $this->generateSampleData($template->variables, $data);

            return $this->renderTemplate($templateId, $sampleData);

        } catch (\Exception $e) {
            throw new CampaignTemplateException(
                "Failed to preview template: {$e->getMessage()}"
            );
        }
    }

    /**
     * Validate template content
     */
    protected function validateTemplateContent(array $content, string $type): void
    {
        switch ($type) {
            case 'email':
                if (empty($content['subject']) || empty($content['body'])) {
                    throw new CampaignTemplateException(
                        "Email template must have subject and body"
                    );
                }
                break;

            case 'sms':
                if (empty($content['message'])) {
                    throw new CampaignTemplateException(
                        "SMS template must have message content"
                    );
                }
                break;

            case 'notification':
                if (empty($content['title']) || empty($content['message'])) {
                    throw new CampaignTemplateException(
                        "Notification template must have title and message"
                    );
                }
                break;
        }
    }

    /**
     * Validate required variables
     */
    protected function validateRequiredVariables(array $required, array $provided): void
    {
        $missing = array_diff(
            array_keys(array_filter($required, fn($var) => $var['required'] ?? false)),
            array_keys($provided)
        );

        if (!empty($missing)) {
            throw new CampaignTemplateException(
                "Missing required variables: " . implode(', ', $missing)
            );
        }
    }

    /**
     * Generate sample data for template preview
     */
    protected function generateSampleData(array $variables, array $provided = []): array
    {
        $sampleData = [];
        foreach ($variables as $key => $config) {
            if (isset($provided[$key])) {
                $sampleData[$key] = $provided[$key];
            } else {
                $sampleData[$key] = $this->generateSampleValue($config);
            }
        }
        return $sampleData;
    }

    /**
     * Generate sample value based on variable configuration
     */
    protected function generateSampleValue(array $config): mixed
    {
        return match ($config['type']) {
            'string' => $config['sample'] ?? 'Sample Text',
            'number' => $config['sample'] ?? 42,
            'date' => $config['sample'] ?? now()->format('Y-m-d'),
            'boolean' => $config['sample'] ?? true,
            'array' => $config['sample'] ?? [],
            default => null
        };
    }

    /**
     * Render content with variables
     */
    protected function renderContent(string $content, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/',
            function($matches) use ($data) {
                $key = $matches[1];
                return $data[$key] ?? '';
            },
            $content
        );
    }

    /**
     * Get template usage statistics
     */
    public function getTemplateUsageStats(int $templateId): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("usage.{$templateId}"),
            300, // 5 minutes cache
            fn() => [
                'total_uses' => DB::table('campaigns')
                    ->where('template_id', $templateId)
                    ->count(),
                'active_campaigns' => DB::table('campaigns')
                    ->where('template_id', $templateId)
                    ->where('status', 'active')
                    ->count(),
                'last_used' => DB::table('campaigns')
                    ->where('template_id', $templateId)
                    ->latest('created_at')
                    ->value('created_at')
            ]
        );
    }
}
