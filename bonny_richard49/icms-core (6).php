<?php
namespace App\Core;

class CMSKernel implements KernelInterface
{
    private SecurityManager $security;
    private ContentManager $content;
    private TemplateManager $template;
    private CacheManager $cache;

    public function __construct()
    {
        $this->bootCriticalSystems();
    }

    private function bootCriticalSystems(): void
    {
        $this->security = new SecurityManager(
            new AuthenticationService(),
            new AuthorizationService(),
            new AuditService()
        );

        $this->content = new ContentManager(
            new ContentRepository(),
            new MediaManager(),
            new ValidationService()
        );

        $this->template = new TemplateManager(
            new TemplateCompiler(),
            new CacheManager()
        );

        $this->cache = new CacheManager();
    }

    public function handle(Request $request): Response
    {
        try {
            $this->security->validateAccess($request);
            
            return $this->security->executeSecured(
                fn() => $this->processRequest($request),
                $request->getSecurityContext()
            );
        } catch (Exception $e) {
            return $this->handleError($e);
        }
    }

    private function processRequest(Request $request): Response
    {
        return match($request->getType()) {
            'content' => $this->handleContent($request),
            'template' => $this->handleTemplate($request),
            default => throw new InvalidRequestException()
        };
    }

    private function handleContent(Request $request): Response
    {
        $result = match($request->getAction()) {
            'create' => $this->content->create($request->getData()),
            'update' => $this->content->update($request->getId(), $request->getData()),
            'delete' => $this->content->delete($request->getId()),
            'publish' => $this->content->publish($request->getId()),
            'get' => $this->content->get($request->getId()),
            default => throw new InvalidActionException()
        };

        return new Response($result);
    }

    private function handleTemplate(Request $request): Response
    {
        $result = match($request->getAction()) {
            'render' => $this->template->render(
                $request->getTemplate(),
                $request->getData()
            ),
            'compile' => $this->template->compile(
                $request->getTemplate(),
                $request->getData()
            ),
            default => throw new InvalidActionException()
        };

        return new Response($result);
    }

    private function handleError(Exception $e): Response
    {
        return new Response([
            'error' => $e->getMessage(),
            'code' => $e->getCode()
        ], $e->getCode());
    }
}

class SecurityManager 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private AuditService $audit;

    public function validateAccess(Request $request): void
    {
        DB::beginTransaction();
        try {
            $user = $this->auth->validate($request);
            if (!$this->authz->hasPermission($user, $request->getRequiredPermission())) {
                throw new UnauthorizedException();
            }
            $this->audit->logAccess($user, $request);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function executeSecured(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        try {
            $result = $operation();
            $this->audit->logOperation($context, $result);
            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}

class ContentManager
{
    private ContentRepository $repository;
    private MediaManager $media;
    private ValidationService $validator;

    public function create(array $data): Content
    {
        $validated = $this->validator->validate($data);
        return DB::transaction(function() use ($validated) {
            $content = $this->repository->create($validated);
            if (isset($validated['media'])) {
                $this->media->attach($content, $validated['media']);
            }
            return $content;
        });
    }

    public function update(int $id, array $data): Content
    {
        $validated = $this->validator->validate($data);
        return DB::transaction(function() use ($id, $validated) {
            $content = $this->repository->update($id, $validated);
            if (isset($validated['media'])) {
                $this->media->sync($content, $validated['media']);
            }
            return $content;
        });
    }

    public function delete(int $id): void
    {
        DB::transaction(function() use ($id) {
            $this->media->detach($id);
            $this->repository->delete($id);
        });
    }

    public function publish(int $id): Content
    {
        return DB::transaction(function() use ($id) {
            $content = $this->repository->find($id);
            $content->publish();
            return $content;
        });
    }
}

class TemplateManager
{
    private TemplateCompiler $compiler;
    private CacheManager $cache;

    public function render(string $template, array $data = []): string
    {
        return $this->cache->remember("template.$template", 3600, function() use ($template, $data) {
            return $this->compiler->compile($template, $data);
        });
    }

    public function compile(string $template, array $data = []): string
    {
        return $this->compiler->compile($template, $data);
    }
}

class CacheManager
{
    public function remember(string $key, int $ttl, callable $callback)
    {
        if ($value = Cache::get($key)) {
            return $value;
        }
        $value = $callback();
        Cache::put($key, $value, $ttl);
        return $value;
    }

    public function forget(string $key): void
    {
        Cache::forget($key);
    }

    public function flush(): void
    {
        Cache::flush();
    }
}

class AuthenticationService
{
    public function validate(Request $request): User
    {
        $credentials = $request->getCredentials();
        $user = $this->validateCredentials($credentials);
        if ($user->hasMfaEnabled()) {
            $this->validateMfa($user, $request->getMfaToken());
        }
        return $user;
    }

    private function validateCredentials(array $credentials): User
    {
        if (!$user = User::findByCredentials($credentials)) {
            throw new InvalidCredentialsException();
        }
        return $user;
    }

    private function validateMfa(User $user, ?string $token): void
    {
        if (!$token || !$user->validateMfaToken($token)) {
            throw new InvalidMfaTokenException();
        }
    }
}

class AuthorizationService
{
    public function hasPermission(User $user, string $permission): bool
    {
        return $user->can($permission);
    }
}

class AuditService
{
    public function logAccess(User $user, Request $request): void
    {
        Log::info('Access', [
            'user' => $user->id,
            'action' => $request->getAction(),
            'ip' => $request->ip()
        ]);
    }

    public function logOperation(SecurityContext $context, $result): void
    {
        Log::info('Operation', [
            'context' => $context->toArray(),
            'result' => $result
        ]);
    }
}
