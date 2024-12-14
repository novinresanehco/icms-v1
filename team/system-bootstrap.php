namespace App\Core;

class SystemBootstrap
{
    private SecurityManager $security;
    private ContentManager $content;
    private TemplateManager $template;
    private InfrastructureManager $infra;

    public function boot(): void
    {
        $this->bootSecurity();
        $this->bootCMS();
        $this->bootTemplates();
        $this->bootInfrastructure();
        $this->registerErrorHandlers();
        $this->initializeCache();
    }

    protected function bootSecurity(): void
    {
        $this->security = new SecurityManager(
            new AuthenticationService(),
            new AuthorizationService(),
            new EncryptionService(config('app.key')),
            new AuditLogger()
        );

        $this->security->registerMiddleware();
        $this->security->initializeFirewall();
    }

    protected function bootCMS(): void
    {
        $this->content = new ContentManager(
            $this->security,
            new ContentRepository(),
            new MediaManager(),
            new CacheManager(),
            new CategoryRepository()
        );

        $this->content->registerRoutes();
        $this->content->initializeAdmin();
    }

    protected function bootTemplates(): void
    {
        $this->template = new TemplateManager(
            new TemplateRepository(),
            $this->security,
            new ViewEngine(),
            new CacheManager()
        );

        $this->template->registerComponents();
        $this->template->initializeEngine();
    }

    protected function bootInfrastructure(): void
    {
        $this->infra = new InfrastructureManager(
            new CacheManager(),
            new EventDispatcher(),
            new ErrorHandler(),
            new MetricsCollector()
        );

        $this->infra->initializeMonitoring();
        $this->infra->registerEventHandlers();
    }

    protected function registerErrorHandlers(): void
    {
        set_error_handler([$this->infra, 'handleError']);
        set_exception_handler([$this->infra, 'handleException']);
        register_shutdown_function([$this->infra, 'handleShutdown']);
    }

    protected function initializeCache(): void
    {
        $cache = new CacheManager();
        $cache->initializeStore();
        $cache->warmEssentialData();
    }

    public function getContainer(): Container
    {
        return new Container([
            SecurityManager::class => $this->security,
            ContentManager::class => $this->content,
            TemplateManager::class => $this->template,
            InfrastructureManager::class => $this->infra
        ]);
    }

    public function handleRequest(Request $request): Response
    {
        try {
            $this->security->validateRequest($request);
            
            return $this->infra->executeOperation(function() use ($request) {
                switch ($request->getType()) {
                    case 'content':
                        return $this->content->handleContentOperation($request);
                    case 'template':
                        return $this->template->handleTemplateOperation($request);
                    default:
                        throw new InvalidOperationException();
                }
            });
        } catch (Exception $e) {
            return $this->handleError($e);
        }
    }

    private function handleError(Exception $e): Response
    {
        $this->infra->handleFailure($e);
        
        return new Response([
            'error' => $e->getMessage(),
            'code' => $e->getCode()
        ], $this->getErrorStatus($e));
    }

    private function getErrorStatus(Exception $e): int
    {
        return match(get_class($e)) {
            AuthenticationException::class => 401,
            AuthorizationException::class => 403,
            ValidationException::class => 422,
            NotFoundException::class => 404,
            default => 500
        };
    }
}
