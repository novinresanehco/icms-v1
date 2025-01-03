<?php

namespace App\Documentation;

use OpenApi\Annotations as OA;
use ReflectionClass;
use ReflectionMethod;

class ApiDocumentationGenerator
{
    private array $routes;
    private array $controllers;
    private array $models;
    private array $securitySchemes;

    public function generate(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => $this->generateInfo(),
            'servers' => $this->generateServers(),
            'paths' => $this->generatePaths(),
            'components' => $this->generateComponents(),
            'security' => $this->generateSecurity(),
            'tags' => $this->generateTags()
        ];
    }

    protected function generateInfo(): array
    {
        return [
            'title' => config('app.name') . ' API',
            'version' => config('app.version'),
            'description' => $this->loadDescription(),
            'contact' => [
                'name' => config('api.contact.name'),
                'email' => config('api.contact.email'),
                'url' => config('api.contact.url')
            ]
        ];
    }

    protected function generatePaths(): array
    {
        $paths = [];

        foreach ($this->routes as $route) {
            $paths[$route->uri] = $this->generatePathItem($route);
        }

        return $paths;
    }

    protected function generatePathItem($route): array
    {
        $controller = $this->controllers[$route->controller];
        $method = new ReflectionMethod($controller, $route->method);
        $docs = $this->parseDocBlock($method);

        return [
            $route->httpMethod => [
                'summary' => $docs->summary,
                'description' => $docs->description,
                'tags' => $docs->tags,
                'parameters' => $this->generateParameters($method),
                'requestBody' => $this->generateRequestBody($method),
                'responses' => $this->generateResponses($method),
                'security' => $this->generateMethodSecurity($docs)
            ]
        ];
    }

    protected function generateComponents(): array
    {
        return [
            'schemas' => $this->generateSchemas(),
            'responses' => $this->generateCommonResponses(),
            'parameters' => $this->generateCommonParameters(),
            'securitySchemes' => $this->securitySchemes,
            'requestBodies' => $this->generateRequestBodies()
        ];
    }

    protected function generateSchemas(): array
    {
        $schemas = [];

        foreach ($this->models as $model) {
            $reflection = new ReflectionClass($model);
            $schemas[$reflection->getShortName()] = $this->generateModelSchema($model);
        }

        return $schemas;
    }

    protected function generateModelSchema($model): array
    {
        $properties = [];
        $required = [];

        foreach ($model->getFillable() as $property) {
            $properties[$property] = $this->getPropertySchema($model, $property);
            
            if ($model->isRequired($property)) {
                $required[] = $property;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required
        ];
    }

    protected function generateParameters(ReflectionMethod $method): array
    {
        $parameters = [];
        $rules = $this->getValidationRules($method);

        foreach ($rules as $param => $rule) {
            $parameters[] = [
                'name' => $param,
                'in' => $this->getParameterLocation($param),
                'required' => $this->isParameterRequired($rule),
                'schema' => $this->getParameterSchema($rule)
            ];
        }

        return $parameters;
    }

    protected function generateResponses(ReflectionMethod $method): array
    {
        $docs = $this->parseDocBlock($method);
        $responses = [];

        foreach ($docs->responses as $code => $response) {
            $responses[$code] = [
                'description' => $response->description,
                'content' => [
                    'application/json' => [
                        'schema' => $response->schema
                    ]
                ]
            ];
        }

        return $responses;
    }

    protected function generateMethodSecurity($docs): array
    {
        $security = [];

        if (isset($docs->security)) {
            foreach ($docs->security as $scheme => $scopes) {
                $security[] = [$scheme => $scopes];
            }
        }

        return $security;
    }

    protected function parseDocBlock(ReflectionMethod $method): object
    {
        $docComment = $method->getDocComment();
        // Parse OpenAPI annotations and return structured object
        return $this->parseOpenApiAnnotations($docComment);
    }

    protected function loadDescription(): string
    {
        $path = resource_path('docs/api/description.md');
        return file_exists($path) ? file_get_contents($path) : '';
    }

    protected function getPropertySchema($model, string $property): array
    {
        $type = $model->getPropertyType($property);
        
        return [
            'type' => $this->mapType($type),
            'format' => $this->getFormat($type),
            'description' => $model->getPropertyDescription($property)
        ];
    }

    protected function mapType(string $type): string
    {
        return match($type) {
            'integer' => 'integer',
            'boolean' => 'boolean',
            'float', 'decimal' => 'number',
            'datetime' => 'string',
            default => 'string'
        };
    }

    protected function getFormat(string $type): ?string
    {
        return match($type) {
            'datetime' => 'date-time',
            'decimal' => 'float',
            'email' => 'email',
            'uuid' => 'uuid',
            default => null
        };
    }

    protected function parseOpenApiAnnotations(string $docComment): object
    {
        // Parse OpenAPI annotations from doc block
        // Return structured object with parsed data
        // Implementation details omitted for brevity
    }
}
