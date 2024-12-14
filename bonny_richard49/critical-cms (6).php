<?php
namespace App\Core;

// Authentication System
class AuthenticationManager {
    private $userRepository;
    private $tokenManager;
    private $sessionManager;
    private $validator;

    public function __construct(
        UserRepository $userRepository,
        TokenManager $tokenManager,
        SessionManager $sessionManager,
        ValidationService $validator
    ) {
        $this->userRepository = $userRepository;
        $this->tokenManager = $tokenManager;
        $this->sessionManager = $sessionManager;
        $this->validator = $validator;
    }

    public function authenticate(array $credentials): AuthResult {
        $this->validator->validate($credentials, [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8']
        ]);

        $user = $this->userRepository->findByCredentials($credentials);
        if (!$user || !$this->validatePassword($user, $credentials['password'])) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($this->isUserLocked($user)) {
            throw new AuthenticationException('Account locked');
        }

        $token = $this->tokenManager->generate($user);
        $this->sessionManager->create($user, $token);
        $this->logAuthenticationSuccess($user);

        return new AuthResult($user, $token);
    }

    public function validateSession(string $token): bool {
        return $this->sessionManager->validate($token);
    }
}

// Core CMS
class ContentManager {
    private $repository;
    private $cache;
    private $validator;
    private $securityManager;

    public function __construct(
        ContentRepository $repository,
        CacheManager $cache,
        ValidationService $validator,
        SecurityManager $securityManager
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->securityManager = $securityManager;
    }

    public function create(array $data): Content {
        $this->validator->validate($data, [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'status' => ['required', 'in:draft,published']
        ]);

        if (!$this->securityManager->canCreateContent($data)) {
            throw new SecurityException('Unauthorized content creation');
        }

        $data = $this->sanitizeContent($data);

        DB::beginTransaction();
        try {
            $content = $this->repository->create($data);
            $this->cache->put("content:{$content->id}", $content);
            $this->logContentCreation($content);
            DB::commit();
            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function find(int $id): ?Content {
        return $this->cache->remember(
            "content:{$id}",
            3600,
            fn() => $this->repository->find($id)
        );
    }
}

class MediaManager {
    private $validator;
    private $securityManager;

    public function __construct(
        ValidationService $validator,
        SecurityManager $securityManager
    ) {
        $this->validator = $validator;
        $this->securityManager = $securityManager;
    }

    public function store(UploadedFile $file): Media {
        $this->validator->validate(['file' => $file], [
            'file' => ['required', 'file', 'max:10240', 'mimes:jpeg,png,pdf,doc,docx']
        ]);

        if (!$this->securityManager->canUploadMedia($file)) {
            throw new SecurityException('Unauthorized media upload');
        }
        $path = $file->store('media');
        return new Media([
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType()
        ]);
    }
}

// Template System
class TemplateManager {
    private $cache;
    private $compiler;
    private $securityManager;
    private $validator;
    
    public function __construct(
        CacheManager $cache,
        TemplateCompiler $compiler,
        SecurityManager $securityManager,
        ValidationService $validator
    ) {
        $this->cache = $cache;
        $this->compiler = $compiler;
        $this->securityManager = $securityManager;
        $this->validator = $validator;
    }

    public function render(string $template, array $data = []): string {
        $this->validator->validate(['template' => $template], [
            'template' => ['required', 'string', 'max:255']
        ]);

        if (!$this->securityManager->canAccessTemplate($template)) {
            throw new SecurityException('Unauthorized template access');
        }

        $data = $this->sanitizeData($data);

        $compiled = $this->cache->remember(
            "template:{$template}",
            3600,
            fn() => $this->compiler->compile($template)
        );

        $this->securityManager->validateTemplate($compiled);
        return $this->evaluateSecure($compiled, $data);
    }

    private function evaluateSecure(string $compiled, array $data): string {
        try {
            $sandbox = new TemplateSandbox();
            return $sandbox->execute(function() use ($compiled, $data) {
                extract($this->escapeData($data));
                ob_start();
                eval('?>' . $compiled);
                return ob_get_clean();
            });
        } catch (\Throwable $e) {
            throw new TemplateExecutionException('Template execution failed', 0, $e);
        }
    }

    private function sanitizeData(array $data): array {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    private function escapeData(array $data): array {
        $escapedData = [];
        foreach ($data as $key => $value) {
            if (!$this->securityManager->isAllowedTemplateVar($key)) {
                continue;
            }
            $escapedData[$key] = $value;
        }
        return $escapedData;
    }
}

// Essential Infrastructure
class CacheManager {
    private $store;
    private $securityManager;
    
    public function __construct(
        CacheStore $store,
        SecurityManager $securityManager
    ) {
        $this->store = $store;
        $this->securityManager = $securityManager;
    }
    
    public function remember(string $key, int $ttl, callable $callback) {
        $value = $this->store->get($key);
        if ($value !== null) return $value;
        
        $value = $callback();
        $this->store->put($key, $value, $ttl);
        return $value;
    }
}

class ErrorHandler {
    private $logger;

    public function handle(\Throwable $e): void {
        $this->logger->error($e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);
        
        if ($e instanceof HttpException) {
            http_response_code($e->getCode());
            echo json_encode(['error' => $e->getMessage()]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
}

class DatabaseManager {
    private $connection;
    private $transactions = 0;

    public function beginTransaction(): void {
        if ($this->transactions === 0) {
            $this->connection->beginTransaction();
        }
        $this->transactions++;
    }

    public function commit(): void {
        if ($this->transactions === 1) {
            $this->connection->commit();
        }
        $this->transactions = max(0, $this->transactions - 1);
    }

    public function rollBack(): void {
        if ($this->transactions === 1) {
            $this->connection->rollBack();
        }
        $this->transactions = max(0, $this->transactions - 1);
    }
}

// Core Data Objects
class Content {
    public $id;
    public $title;
    public $body;
    public $status;
    public $userId;
}

class Media {
    public $id;
    public $path;
    public $filename;
    public $mimeType;
}

class User {
    public $id;
    public $email;
    public $password;
    public $roles = [];
}

class AuthResult {
    public $user;
    public $token;
    
    public function __construct(User $user, string $token) {
        $this->user = $user;
        $this->token = $token;
    }
}

// Core Exceptions
class AuthenticationException extends \Exception {}
class HttpException extends \Exception {}
class ValidationException extends \Exception {}
