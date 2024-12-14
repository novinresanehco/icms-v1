<?php

namespace App\Core\Template\Factory;

class TemplateFactory implements FactoryInterface
{
    private SecurityManager $security;
    private ValidatorInterface $validator;
    private CacheManager $cache;
    private array $registered = [];

    public function __construct(
        SecurityManager $security, 
        ValidatorInterface $validator,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function create(string $type, array $config): TemplateInterface
    {
        return DB::transaction(function() use ($type, $config) {
            $this->validator->validateTemplateType($type);
            $this->security->validateTemplateCreation($type, $config);

            $template = match($type) {
                'page' => new PageTemplate($config),
                'component' => new ComponentTemplate($config),
                'layout' => new LayoutTemplate($config),
                default => throw new InvalidTemplateType($type)
            };

            $template->setValidator($this->validator);
            $template->setCache($this->cache);
            
            $this->registerTemplate($template);
            
            return $template;
        });
    }

    private function registerTemplate(TemplateInterface $template): void
    {
        $this->registered[$template->getId()] = $template;
    }
}

abstract class BaseTemplate implements TemplateInterface
{
    protected string $id;
    protected array $config;
    protected ValidatorInterface $validator;
    protected CacheManager $cache;
    
    public function __construct(array $config)
    {
        $this->id = uniqid('tpl_');
        $this->config = $config;
    }

    public function setValidator(ValidatorInterface $validator): void
    {
        $this->validator = $validator;
    }

    public function setCache(CacheManager $cache): void
    {
        $this->cache = $cache;
    }

    public function getId(): string
    {
        return $this->id;
    }

    abstract public function render(array $data = []): string;
}

class PageTemplate extends BaseTemplate
{
    public function render(array $data = []): string
    {
        return DB::transaction(function() use ($data) {
            $this->validator->validatePageData($data);
            
            return $this->cache->remember("page:{$this->id}", function() use ($data) {
                return view($this->config['view'], array_merge(
                    $this->config['defaults'] ?? [],
                    $data
                ))->render();
            });
        });
    }
}

class ComponentTemplate extends BaseTemplate
{
    public function render(array $data = []): string
    {
        return DB::transaction(function() use ($data) {
            $this->validator->validateComponentData($data);
            
            return $this->cache->remember("component:{$this->id}", function() use ($data) {
                return view("components.{$this->config['name']}", $data)->render();
            });
        });
    }
}

class LayoutTemplate extends BaseTemplate
{
    public function render(array $data = []): string
    {
        return DB::transaction(function() use ($data) {
            $this->validator->validateLayoutData($data);
            
            return $this->cache->remember("layout:{$this->id}", function() use ($data) {
                return view("layouts.{$this->config['name']}", array_merge(
                    ['content' => $data['content'] ?? ''],
                    $data
                ))->render();
            });
        });
    }
}

interface FactoryInterface
{
    public function create(string $type, array $config): TemplateInterface;
}

interface TemplateInterface
{
    public function render(array $data = []): string;
    public function getId(): string;
    public function setValidator(ValidatorInterface $validator): void;
    public function setCache(CacheManager $cache): void;
}
