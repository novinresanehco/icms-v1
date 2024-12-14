<?php
namespace App\Core\Template;

class TemplateComponentSystem {
    private SecurityManager $security;
    private ValidationService $validator;
    private RenderEngine $engine;
    private CacheManager $cache;

    public function process(RenderContext $context): RenderResult {
        try {
            $this->security->validateContext($context);
            $validated = $this->validator->validateInput($context);
            
            return $this->cache->remember(
                $this->getCacheKey($context),
                fn() => $this->renderSecure($validated)
            );
        } catch (SecurityException $e) {
            throw new RenderException('Component render failed: ' . $e->getMessage());
        }
    }

    private function renderSecure(ValidatedContext $context): RenderResult {
        $component = $this->engine->loadComponent($context);
        $rendered = $this->engine->renderComponent($component);
        $validated = $this->validator->validateOutput($rendered);
        
        return new RenderResult($validated);
    }

    private function getCacheKey(RenderContext $context): string {
        return sprintf('component.%s', $context->getId());
    }
}

class RenderEngine {
    private array $components = [];

    public function loadComponent(ValidatedContext $context): Component {
        if (!isset($this->components[$context->getType()])) {
            throw new ComponentException("Invalid component type");
        }
        return $this->components[$context->getType()];
    }

    public function renderComponent(Component $component): string {
        return $component->render();
    }
}

class SecurityManager {
    public function validateContext(RenderContext $context): void {
        if (!$this->isSecure($context)) {
            throw new SecurityException('Invalid security context');
        }
    }

    private function isSecure(RenderContext $context): bool {
        return true;
    }
}

class ValidationService {
    public function validateInput(RenderContext $context): ValidatedContext {
        return new ValidatedContext($context->getData());
    }

    public function validateOutput(string $output): string {
        return $output;
    }
}

class Component {
    private string $type;
    private array $props;

    public function render(): string {
        return '';
    }
}

class RenderContext {
    private string $id;
    private string $type;
    private array $data;

    public function getId(): string {
        return $this->id;
    }

    public function getType(): string {
        return $this->type;
    }

    public function getData(): array {
        return $this->data;
    }
}

class ValidatedContext {
    private array $data;
    private string $type;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function getType(): string {
        return $this->type;
    }
}

class RenderResult {
    private string $output;

    public function __construct(string $output) {
        $this->output = $output;
    }
}

class SecurityException extends \Exception {}
class RenderException extends \Exception {}
class ComponentException extends \Exception {}
