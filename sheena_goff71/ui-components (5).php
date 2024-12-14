<?php

namespace App\Core\UI;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Template\TemplateEngineInterface;

class UIComponentManager implements ComponentManagerInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private TemplateEngineInterface $template;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        TemplateEngineInterface $template
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->template = $template;
    }

    public function render(string $component, array $props = []): string 
    {
        $cacheKey = $this->generateCacheKey($component, $props);

        return $this->cache->remember($cacheKey, function() use ($component, $props) {
            $this->validateComponent($component);
            $validatedProps = $this->validateProps($component, $props);
            
            return $this->template->render(
                "components.{$component}",
                $this->prepareProps($validatedProps)
            );
        });
    }

    public function renderForm(string $form, array $data = []): string 
    {
        $this->security->validateFormRequest($form);
        
        return $this->render('form', [
            'form' => $form,
            'fields' => $this->prepareFormFields($form, $data),
            'token' => $this->security->generateFormToken($form)
        ]);
    }

    public function renderDataTable(string $source, array $config = []): string 
    {
        $this->security->validateDataSource($source);
        
        return $this->render('data-table', [
            'source' => $this->security->sanitizeUrl($source),
            'columns' => $this->prepareColumns($config['columns'] ?? []),
            'options' => $this->prepareTableOptions($config)
        ]);
    }

    private function validateComponent(string $component): void 
    {
        if (!$this->security->validateComponent($component)) {
            throw new ComponentException("Invalid component: {$component}");
        }
    }

    private function validateProps(string $component, array $props): array 
    {
        $schema = $this->getComponentSchema($component);
        return $this->security->validateProps($props, $schema);
    }

    private function prepareProps(array $props): array 
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->security->sanitizeOutput($value);
            }
            return $value;
        }, $props);
    }

    private function prepareFormFields(string $form, array $data): array 
    {
        $fields = $this->getFormSchema($form);
        
        return array_map(function ($field) use ($data) {
            return [
                'type' => $field['type'],
                'name' => $field['name'],
                'value' => $this->security->sanitize($data[$field['name']] ?? null),
                'rules' => $field['validation'] ?? []
            ];
        }, $fields);
    }

    private function prepareColumns(array $columns): array 
    {
        return array_map(function ($column) {
            return [
                'field' => $this->security->sanitize($column['field']),
                'title' => $this->security->sanitize($column['title']),
                'sortable' => $column['sortable'] ?? false,
                'filterable' => $column['filterable'] ?? false
            ];
        }, $columns);
    }

    private function prepareTableOptions(array $config): array 
    {
        return [
            'pagination' => $config['pagination'] ?? true,
            'pageSize' => min($config['pageSize'] ?? 10, 100),
            'sortable' => $config['sortable'] ?? true,
            'filterable' => $config['filterable'] ?? true
        ];
    }

    private function generateCacheKey(string $component, array $props): string 
    {
        return sprintf(
            'component:%s:%s',
            $component,
            md5(serialize($props))
        );
    }

    private function getComponentSchema(string $component): array 
    {
        return require __DIR__ . "/schemas/{$component}.php";
    }

    private function getFormSchema(string $form): array 
    {
        return require __DIR__ . "/forms/{$form}.php";
    }
}
