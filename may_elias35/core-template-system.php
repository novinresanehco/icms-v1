<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View};
use App\Core\Security\SecurityAware;
use App\Core\Cache\CacheManager;

class TemplateManager
{
    use SecurityAware;

    private CacheManager $cache;
    private TemplateRepository $repository;
    private SecurityValidator $security;

    public function __construct(
        CacheManager $cache,
        TemplateRepository $repository,
        SecurityValidator $security
    ) {
        $this->cache = $cache;
        $this->repository = $repository;
        $this->security = $security;
    }

    public function render(string $template, array $data, UserEntity $user): string
    {
        $this->checkPermission($user, 'template.view');
        
        $template = $this->loadTemplate($template);
        $this->security->validateTemplate($template, $data);
        
        return View::make($template->path, $this->sanitizeData($data))->render();
    }

    public function store(string $name, array $content, UserEntity $user): Template
    {
        $this->checkPermission($user, 'template.create');
        
        return DB::transaction(function() use ($name, $content) {
            $template = $this->repository->create([
                'name' => $name,
                'content' => $this->security->sanitizeContent($content),
                'hash' => hash('sha256', serialize($content))
            ]);
            
            $this->cache->forget("template.{$name}");
            return $template;
        });
    }

    protected function loadTemplate(string $name): Template
    {
        return $this->cache->remember(
            "template.{$name}",
            fn() => $this->repository->findByName($name)
        );
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(
            fn($value) => $this->security->sanitizeValue($value),
            $data
        );
    }
}

class SecurityValidator
{
    private array $allowedTags = [
        'div', 'span', 'p', 'h1', 'h2', 'h3', 'table', 'tr', 'td', 'th'
    ];

    public function validateTemplate(Template $template, array $data): void
    {
        if (!$this->validateHash($template)) {
            throw new SecurityException('Template integrity check failed');
        }

        if (!$this->validateContent($template->content)) {
            throw new SecurityException('Template contains unsafe content');
        }

        if (!$this->validateData($data)) {
            throw new SecurityException('Template data validation failed');
        }
    }

    public function sanitizeContent(array $content): array
    {
        return array_map(
            fn($section) => strip_tags($section, $this->allowedTags),
            $content
        );
    }

    public function sanitizeValue($value): string
    {
        if (is_array($value)) {
            return htmlspecialchars(json_encode($value));
        }
        return htmlspecialchars((string)$value, ENT_QUOTES);
    }

    protected function validateHash(Template $template): bool
    {
        return hash('sha256', serialize($template->content)) === $template->hash;
    }

    protected function validateContent(array $content): bool
    {
        foreach ($content as $section) {
            if ($this->containsUnsafeContent($section)) {
                return false;
            }
        }
        return true;
    }

    protected function validateData(array $data): bool
    {
        foreach ($data as $value) {
            if ($this->containsUnsafeData($value)) {
                return false;
            }
        }
        return true;
    }

    protected function containsUnsafeContent(string $content): bool
    {
        return preg_match('/<script|<iframe|javascript:|data:/i', $content) === 1;
    }

    protected function containsUnsafeData($value): bool
    {
        if (is_array($value)) {
            return array_reduce(
                $value,
                fn($carry, $item) => $carry || $this->containsUnsafeData($item),
                false
            );
        }
        return preg_match('/[<>\'"]|\.\.|\/\/|\\\/i', (string)$value) === 1;
    }
}

class CacheManager
{
    private int $defaultTtl = 3600;

    public function remember(string $key, callable $callback): mixed
    {
        return Cache::remember($key, $this->defaultTtl, $callback);
    }

    public function forget(string $key): void
    {
        Cache::forget($key);
    }
}
