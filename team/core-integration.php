<?php

namespace App\Core;

use App\Core\Security\SecurityManager;
use App\Core\Auth\AuthenticationManager;
use App\Core\CMS\ContentManager;
use App\Core\Template\TemplateManager;
use App\Core\Infrastructure\InfrastructureManager;

class CoreSystem
{
    private SecurityManager $security;
    private AuthenticationManager $auth;
    private ContentManager $cms;
    private TemplateManager $template;
    private InfrastructureManager $infrastructure;

    public function __construct(
        SecurityManager $security,
        AuthenticationManager $auth,
        ContentManager $cms,
        TemplateManager $template,
        InfrastructureManager $infrastructure
    ) {
        $this->security = $security;
        $this->auth = $auth;
        $this->cms = $cms;
        $this->template = $template;
        $this->infrastructure = $infrastructure;
    }

    public function handleRequest(Request $request): Response
    {
        $operationId = $this->infrastructure->startCriticalOperation(
            $request->route()->getName()
        );

        try {
            if (!$this->auth->validateToken($request->bearerToken())) {
                throw new AuthenticationException('Invalid token');
            }

            $response = $this->processRequest($request);

            $this->infrastructure->endOperation($operationId);
            return $response;

        } catch (\Exception $e) {
            $this->infrastructure->handleFailure($operationId, $e);
            throw $e;
        }
    }

    private function processRequest(Request $request): Response
    {
        $data = $this->security->validateInput($request->all());
        
        $content = match($request->method()) {
            'GET' => $this->cms->find($data['id']),
            'POST' => $this->cms->store($data),
            'PUT' => $this->cms->update($data['id'], $data),
            'DELETE' => $this->cms->delete($data['id']),
            default => throw new InvalidRequestException('Invalid method')
        };

        return $this->renderResponse($content);
    }

    private function renderResponse($content): Response
    {
        $template = $content->template ?? 'default';
        $rendered = $this->template->render($template, ['content' => $content]);
        
        return new Response(
            $rendered,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }
}

class KernelMiddleware
{
    private CoreSystem $core;

    public function handle(Request $request, \Closure $next)
    {
        return $this->core->handleRequest($request);
    }
}

class ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CoreSystem::class);
        $this->app->singleton(SecurityManager::class);
        $this->app->singleton(AuthenticationManager::class);
        $this->app->singleton(ContentManager::class);
        $this->app->singleton(TemplateManager::class);
        $this->app->singleton(InfrastructureManager::class);
    }

    public function boot(): void
    {
        $this->app->router->aliasMiddleware('core', KernelMiddleware::class);
    }
}

class AppConfig 
{
    const SECURITY = [
        'token_ttl' => 3600,
        'token_refresh' => 300,
        'max_attempts' => 3,
        'lockout_time' => 900,
    ];

    const CACHE = [
        'default_ttl' => 3600,
        'long_ttl' => 86400,
        'invalidation_mode' => 'smart'
    ];

    const INFRASTRUCTURE = [
        'monitor_interval' => 60,
        'metrics_retention' => 2592000,
        'log_channels' => [
            'operations',
            'security',
            'performance'
        ]
    ];
}
