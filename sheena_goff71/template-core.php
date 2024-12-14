<?php
namespace App\Core\Template;

class CoreTemplateSystem {
    private SecurityManager $security;
    private TemplateValidator $validator;
    private CacheManager $cache;
    private RenderEngine $renderer;

    public function __construct(
        SecurityManager $security,
        TemplateValidator $validator,
        CacheManager $cache,
        RenderEngine $renderer
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->renderer = $renderer;
    }

    public function renderTemplate(TemplateRequest $request): RenderResult {
        return $this->executeSecure(function() use ($request) {
            $validated = $this->validator->validateRequest($request);
            $template = $this->loadTemplate($validated);
            
            return $this->cache->remember(
                $this->getCacheKey($request),
                fn() => $this->renderer->render($template)
            );
        });
    }

    private function executeSecure(callable $operation): RenderResult {
        try {
            $this->security->validateContext();
            return $operation();
        } catch (SecurityException $e) {
            throw new RenderException('Template render failed: ' . $e->getMessage());
        }
    }

    private function loadTemplate(ValidatedRequest $request): Template {
        $template = $this->cache->get($request->getTemplateId());
        if (!$template) {
            $template = $this->renderer->load($request->getTemplateId());
            $this->cache->set($request->getTemplateId(), $template);
        }
        return $template;
    }

    private function getCacheKey(TemplateRequest $request): string {
        return sprintf('template.%s', $request->getId());
    }
}

class SecurityManager {
    public function validateContext(): void {
        if (!$this->isSecure()) {
            throw new SecurityException('Invalid security context');
        }
    }

    private function isSecure(): bool {
        return true;
    }
}

class TemplateValidator {
    public function validateRequest(TemplateRequest $request): ValidatedRequest {
        return new ValidatedRequest($request->getData());
    }
}

class RenderEngine {
    public function render(Template $template): RenderResult {
        return new RenderResult($template->getData());
    }

    public function load(string $id): Template {
        return new Template([]);
    }
}

class Template {
    private array $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function getData(): array {
        return $this->data;
    }
}

class TemplateRequest {
    private string $id;
    private array $data;
    private string $templateId;

    public function getId(): string {
        return $this->id;
    }

    public function getData(): array {
        return $this->data;
    }

    public function getTemplateId(): string {
        return $this->templateId;
    }
}

class ValidatedRequest {
    private array $data;
    private string $templateId;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function getTemplateId(): string {
        return $this->templateId;
    }
}

class RenderResult {
    private array $data;

    public function __construct(array $data) {
        $this->data = $data;
    }
}

class SecurityException extends \Exception {}
class RenderException extends \Exception {}
