<?php

namespace App\Core\Template\UI;

class UIComponentRegistry implements ComponentInterface
{
    private SecurityManager $security;
    private ValidatorInterface $validator;
    private array $components = [];

    public function __construct(
        SecurityManager $security,
        ValidatorInterface $validator
    ) {
        $this->security = $security;
        $this->validator = $validator;
    }

    public function register(string $name, BaseComponent $component): void
    {
        $this->validator->validateComponentName($name);
        $this->components[$name] = $component;
    }

    public function render(string $name, array $props = []): string
    {
        return DB::transaction(function() use ($name, $props) {
            $component = $this->resolve($name);
            $validatedProps = $this->validator->validateProps($props);
            
            return $component->render($validatedProps);
        });
    }

    private function resolve(string $name): BaseComponent
    {
        if (!isset($this->components[$name])) {
            throw new ComponentNotFoundException($name);
        }
        return $this->components[$name];
    }
}

abstract class BaseComponent
{
    protected SecurityManager $security;
    protected ValidatorInterface $validator;

    public function __construct(
        SecurityManager $security,
        ValidatorInterface $validator
    ) {
        $this->security = $security;
        $this->validator = $validator;
    }

    abstract public function render(array $props): string;
    abstract protected function validate(array $props): void;
}

class FormComponent extends BaseComponent
{
    public function render(array $props): string
    {
        $this->validate($props);
        $this->security->validateFormTokens($props);

        return view('components.form', [
            'method' => $props['method'],
            'action' => $props['action'],
            'csrf' => $this->security->generateToken(),
            'fields' => $this->processFields($props['fields'])
        ])->render();
    }

    protected function validate(array $props): void
    {
        $this->validator->validateFormProps($props);
    }

    private function processFields(array $fields): array
    {
        return array_map(function($field) {
            return $this->validateField($field);
        }, $fields);
    }
}

class TableComponent extends BaseComponent
{
    public function render(array $props): string
    {
        $this->validate($props);
        
        return view('components.table', [
            'headers' => $this->processHeaders($props['headers']),
            'rows' => $this->processRows($props['rows']),
            'sortable' => $props['sortable'] ?? false,
            'pagination' => $props['pagination'] ?? null
        ])->render();
    }

    protected function validate(array $props): void
    {
        $this->validator->validateTableProps($props);
    }

    private function processHeaders(array $headers): array
    {
        return array_map(fn($header) => $this->sanitizeHeader($header), $headers);
    }

    private function processRows(array $rows): array
    {
        return array_map(fn($row) => $this->sanitizeRow($row), $rows);
    }
}

class MediaComponent extends BaseComponent
{
    public function render(array $props): string
    {
        $this->validate($props);
        $this->security->validateMediaAccess($props['src']);

        return view('components.media', [
            'src' => $this->processMediaSource($props['src']),
            'type' => $props['type'],
            'attributes' => $this->processAttributes($props['attributes'] ?? [])
        ])->render();
    }

    protected function validate(array $props): void
    {
        $this->validator->validateMediaProps($props);
    }

    private function processMediaSource(string $src): string
    {
        return $this->security->sanitizeMediaUrl($src);
    }
}

interface ComponentInterface
{
    public function register(string $name, BaseComponent $component): void;
    public function render(string $name, array $props = []): string;
}
