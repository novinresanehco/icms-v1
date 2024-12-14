<?php
namespace App\Core;

abstract class CoreException extends \Exception {
    protected bool $isCritical = false;
    
    public function isCritical(): bool {
        return $this->isCritical;
    }
}

class SecurityException extends CoreException {
    protected bool $isCritical = true;
}

class AuthException extends CoreException {
    protected bool $isCritical = true;
}

class ContentException extends CoreException {
    protected bool $isCritical = false;
}

class TemplateException extends CoreException {
    protected bool $isCritical = false;
}

class InfrastructureException extends CoreException {
    protected bool $isCritical = true;
}

class SystemBootstrap {
    private SecurityInterface $security;
    private AuthInterface $auth;
    private ContentInterface $content;
    private TemplateInterface $template;
    private InfrastructureInterface $infrastructure;
    private CacheInterface $cache;
    private ValidationInterface $validation;

    public function __construct(array $config) {
        $this->security = new SecurityCore();
        $this->cache = new CoreCache($this->security);
        $this->validation = new CoreValidation($this->security, $config['security_rules']);
        $this->auth = new AuthManager($this->security);
        $this->content = new ContentManager($this->security);
        $this->template = new TemplateManager($this->security, $config['template_path']);
        $this->infrastructure = new InfrastructureManager($this->security);
    }

    public function init(): void {
        try {
            $this->infrastructure->startOperation('system_boot');
            
            if (!$this->validation->validateSecurity(['context' => 'boot'])) {
                throw new SecurityException('Security validation failed during boot');
            }

            $health = $this->infrastructure->checkHealth();
            if (!all($health)) {
                throw new InfrastructureException('System health check failed');
            }
            
            $this->infrastructure->endOperation('system_boot');
        } catch (\Throwable $e) {
            Log::critical('System boot failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getAuth(): AuthInterface {
        return $this->auth;
    }

    public function getContent(): ContentInterface {
        return $this->content;
    }

    public function getTemplate(): TemplateInterface {
        return $this->template;
    }

    public function getInfrastructure(): InfrastructureInterface {
        return $this->infrastructure;
    }

    public function getCache(): CacheInterface {
        return $this->cache;
    }

    public function getValidation(): ValidationInterface {
        return $this->validation;
    }
} {
    private SecurityInterface $security;
    private AuthInterface $auth;
    private ContentInterface $content;
    private TemplateInterface $template;
    private InfrastructureInterface $infrastructure;

    public function __construct(array $config) {
        $this->security = new SecurityCore();
        $this->auth = new AuthManager($this->security);
        $this->content = new ContentManager($this->security);
        $this->template = new TemplateManager($this->security, $config['template_path']);
        $this->infrastructure = new InfrastructureManager($this->security);
    }

    public function init(): void {
        try {
            $this->infrastructure->startOperation('system_boot');
            $health = $this->infrastructure->checkHealth();
            
            if (!all($health)) {
                throw new InfrastructureException('System health check failed');
            }
            
            $this->infrastructure->endOperation('system_boot');
        } catch (\Throwable $e) {
            Log::critical('System boot failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getAuth(): AuthInterface {
        return $this->auth;
    }

    public function getContent(): ContentInterface {
        return $this->content;
    }

    public function getTemplate(): TemplateInterface {
        return $this->template;
    }

    public function getInfrastructure(): InfrastructureInterface {
        return $this->infrastructure;
    }
}

namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Hash, Storage, Log};

interface SecurityInterface {
    public function validateOperation(callable $operation, array $context): mixed;
    public function validateToken(string $token): bool;
}

interface AuthInterface {
    public function authenticate(array $credentials): array;
    public function validateAccess(string $token, string $permission): bool;
}

interface ContentInterface {
    public function createContent(array $data, string $token): array;
    public function updateContent(int $id, array $data, string $token): array;
}

interface TemplateInterface {
    public function render(string $template, array $data, string $token): string;
}

interface InfrastructureInterface {
    public function startOperation(string $operation): void;
    public function endOperation(string $operation): float;
    public function checkHealth(): array;
    public function getMetrics(): array;
}

class SecurityCore implements SecurityInterface {
    private array $activeOperations = [];

    public function validateOperation(callable $operation, array $context): mixed {
        $id = uniqid();
        $this->activeOperations[$id] = microtime(true);
        
        DB::beginTransaction();
        try {
            $result = $operation();
            DB::commit();
            $this->logSuccess($id, $context);
            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->logFailure($id, $context, $e);
            throw $e;
        } finally {
            unset($this->activeOperations[$id]);
        }
    }

    public function validateToken(string $token): bool {
        try {
            return Cache::get("auth_token_{$token}") !== null;
        } catch (\Throwable $e) {
            $this->logError('token_validation', $e);
            return false;
        }
    }

    private function logSuccess(string $id, array $context): void {
        $duration = microtime(true) - $this->activeOperations[$id];
        Log::info('Operation completed', [
            'id' => $id,
            'context' => $context,
            'duration' => $duration
        ]);
    }

    private function logFailure(string $id, array $context, \Throwable $e): void {
        Log::error('Operation failed', [
            'id' => $id,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function logError(string $context, \Throwable $e): void {
        Log::error('Security error', [
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class AuthManager implements AuthInterface {
    private SecurityInterface $security;

    public function __construct(SecurityInterface $security) {
        $this->security = $security;
    }

    public function authenticate(array $credentials): array {
        return $this->security->validateOperation(function() use ($credentials) {
            $user = DB::table('users')->where('email', $credentials['email'])->first();
            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                throw new AuthException('Invalid credentials');
            }
            $token = bin2hex(random_bytes(32));
            $expiresAt = time() + 3600;
            Cache::put("auth_token_{$token}", [
                'user_id' => $user->id,
                'expires_at' => $expiresAt
            ], 3600);
            return ['token' => $token, 'user' => $user, 'expires_at' => $expiresAt];
        }, ['context' => 'auth']);
    }

    public function validateAccess(string $token, string $permission): bool {
        $data = Cache::get("auth_token_{$token}");
        if (!$data || time() > $data['expires_at']) {
            return false;
        }
        return DB::table('user_permissions')
            ->where('user_id', $data['user_id'])
            ->where('permission', $permission)
            ->exists();
    }
}

class ContentManager implements ContentInterface {
    private SecurityInterface $security;

    public function __construct(SecurityInterface $security) {
        $this->security = $security;
    }

    public function createContent(array $data, string $token): array {
        return $this->security->validateOperation(function() use ($data) {
            $this->validateContent($data);
            
            $contentId = DB::table('content')->insertGetId([
                'title' => $data['title'],
                'content' => $data['content'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            if (isset($data['media'])) {
                $this->processMedia($contentId, $data['media']);
            }
            
            return DB::table('content')->find($contentId);
        }, ['context' => 'content_create']);
    }

    public function updateContent(int $id, array $data, string $token): array {
        return $this->security->validateOperation(function() use ($id, $data) {
            $this->validateContent($data);
            
            DB::table('content')
                ->where('id', $id)
                ->update([
                    'title' => $data['title'],
                    'content' => $data['content'],
                    'updated_at' => now()
                ]);
            
            if (isset($data['media'])) {
                $this->processMedia($id, $data['media']);
            }
            
            return DB::table('content')->find($id);
        }, ['context' => 'content_update']);
    }

    private function validateContent(array $data): void {
        if (empty($data['title']) || empty($data['content'])) {
            throw new ContentException('Invalid content data');
        }
    }

    private function processMedia(int $contentId, array $media): void {
        foreach ($media as $file) {
            $path = Storage::put("content/{$contentId}", $file);
            DB::table('content_media')->insert([
                'content_id' => $contentId,
                'path' => $path,
                'created_at' => now()
            ]);
        }
    }
}

class TemplateManager implements TemplateInterface {
    private SecurityInterface $security;
    private string $basePath;

    public function __construct(SecurityInterface $security, string $basePath) {
        $this->security = $security;
        $this->basePath = $basePath;
    }

    public function render(string $template, array $data, string $token): string {
        return $this->security->validateOperation(function() use ($template, $data) {
            $key = "template_{$template}_" . md5(serialize($data));
            
            if ($cached = Cache::get($key)) {
                return $cached;
            }
            
            $content = $this->loadTemplate($template);
            $rendered = $this->processTemplate($content, $data);
            
            Cache::put($key, $rendered, 3600);
            return $rendered;
        }, ['context' => 'template_render']);
    }

    private function loadTemplate(string $template): string {
        $path = $this->basePath . '/' . $template;
        if (!Storage::exists($path)) {
            throw new TemplateException('Template not found');
        }
        return Storage::get($path);
    }

    private function processTemplate(string $content, array $data): string {
        foreach ($data as $key => $value) {
            $content = str_replace(
                "{{$key}}", 
                htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'),
                $content
            );
        }
        return $content;
    }
}

class InfrastructureManager implements InfrastructureInterface {
    private SecurityInterface $security;
    private array $metrics = [];

    public function __construct(SecurityInterface $security) {
        $this->security = $security;
    }

    public function startOperation(string $operation): void {
        $this->metrics[$operation] = ['start' => microtime(true)];
    }

    public function endOperation(string $operation): float {
        if (!isset($this->metrics[$operation])) {
            throw new InfrastructureException('Operation not found');
        }
        $duration = microtime(true) - $this->metrics[$operation]['start'];
        $this->metrics[$operation]['duration'] = $duration;
        return $duration;
    }

    public function checkHealth(): array {
        return $this->security->validateOperation(function() {
            return [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'storage' => $this->checkStorage(),
            ];
        }, ['context' => 'health_check']);
    }

    public function getMetrics(): array {
        return $this->metrics;
    }

    private function checkDatabase(): bool {
        try {
            return !empty(DB::select('SELECT 1')[0]);
        } catch (\Throwable $e) {
            Log::error('Database check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function checkCache(): bool {
        try {
            $key = 'health_' . uniqid();
            return Cache::set($key, true, 1) && Cache::get($key) === true;
        } catch (\Throwable $e) {
            Log::error('Cache check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function checkStorage(): bool {
        try {
            $key = 'health_' . uniqid();
            return Storage::put($key, 'test') && 
                   Storage::get($key) === 'test' && 
                   Storage::delete($key);
        } catch (\Throwable $e) {
            Log::error('Storage check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
