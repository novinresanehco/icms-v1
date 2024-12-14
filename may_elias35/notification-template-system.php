// File: app/Core/Notification/Template/NotificationTemplateManager.php
<?php

namespace App\Core\Notification\Template;

class NotificationTemplateManager
{
    protected TemplateRepository $repository;
    protected TemplateRenderer $renderer;
    protected TemplateValidator $validator;
    protected TemplateCache $cache;

    public function render(string $template, array $data): string
    {
        $template = $this->getTemplate($template);
        return $this->renderer->render($template, $data);
    }

    public function createTemplate(array $data): Template
    {
        $this->validator->validate($data);
        
        $template = $this->repository->create([
            'name' => $data['name'],
            'content' => $data['content'],
            'type' => $data['type'],
            'variables' => $data['variables'] ?? []
        ]);

        $this->cache->invalidate($template->getName());
        return $template;
    }

    protected function getTemplate(string $name): Template
    {
        return $this->cache->remember($name, function() use ($name) {
            return $this->repository->findByName($name);
        });
    }
}

// File: app/Core/Notification/Template/TemplateRenderer.php
<?php

namespace App\Core\Notification\Template;

class TemplateRenderer
{
    protected VariableProcessor $variableProcessor;
    protected ConditionProcessor $conditionProcessor;
    protected TemplateConfig $config;

    public function render(Template $template, array $data): string
    {
        $content = $template->getContent();
        
        $content = $this->processVariables($content, $data);
        $content = $this->processConditions($content, $data);
        
        return $this->formatContent($content);
    }

    protected function processVariables(string $content, array $data): string
    {
        return $this->variableProcessor->process($content, $data);
    }

    protected function processConditions(string $content, array $data): string
    {
        return $this->conditionProcessor->process($content, $data);
    }
}
