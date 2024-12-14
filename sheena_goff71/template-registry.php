<?php

namespace App\Core\Template\Registry;

class TemplateRegistry implements RegistryInterface
{
    private SecurityManager $security;
    private array $templates = [];
    private array $components = [];
    private array $media = [];

    public function register(string $type, string $name, array $config): void
    {
        DB::transaction(function() use ($type, $name, $config) {
            $this->security->validateRegistration($type, $name, $config);
            
            match($type) {
                'template' => $this->templates[$name] = $this->processTemplate($config),
                'component' => $this->components[$name] = $this->processComponent($config),
                'media' => $this->media[$name] = $this->processMedia($config),
                default => throw new InvalidRegistrationType($type)
            };
        });
    }

    public function resolve(string $type, string $name): array
    {
        return match($type) {
            'template' => $this->resolveTemplate($name),
            'component' => $this->resolveComponent($name),
            'media' => $this->resolveMedia($name),
            default => throw new InvalidResolutionType($type)
        };
    }

    private function processTemplate(array $config): array
    {
        return [
            'path' => $config['path'],
            'cache' => $config['cache'] ?? true,
            'security' => $config['security'] ?? 'strict',
            'version' => $config['version'] ?? '1.0'
        ];
    }

    private function processComponent(array $config): array
    {
        return [
            'class' => $config['class'],
            'props' => $config['props'] ?? [],
            'boundary' => $config['boundary'] ?? 'strict',
            'cache' => $config['cache'] ?? true
        ];
    }

    private function processMedia(array $config): array
    {
        return [
            'type' => $config['type'],
            'handlers' => $config['handlers'] ?? [],
            'security' => $config['security'] ?? 'strict',
            'optimization' => $config['optimization'] ?? 'auto'
        ];
    }

    private function resolveTemplate(string $name): array
    {
        if (!isset($this->templates[$name])) {
            throw new TemplateNotFoundException($name);
        }
        return $this->templates[$name];
    }

    private function resolveComponent(string $name): array
    {
        if (!isset($this->components[$name])) {
            throw new ComponentNotFoundException($name);
        }
        return $this->components[$name];
    }

    private function resolveMedia(string $name): array
    {
        if (!isset($this->media[$name])) {
            throw new MediaNotFoundException($name);
        }
        return $this->media[$name];
    }
}

interface RegistryInterface
{
    public function register(string $type, string $name, array $config): void;
    public function resolve(string $type, string $name): array;
}

class InvalidRegistrationType extends \Exception {}
class InvalidResolutionType extends \Exception {}
class TemplateNotFoundException extends \Exception {}
class ComponentNotFoundException extends \Exception {}
class MediaNotFoundException extends \Exception {}
