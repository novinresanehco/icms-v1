<?php
namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Hash, Storage};

class SecurityCore {
    public function validateOperation(callable $operation, array $context): mixed {
        DB::beginTransaction();
        try {
            $result = $operation();
            DB::commit();
            return $result;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function validateToken(string $token): bool {
        return Cache::get("auth_token_{$token}") !== null;
    }
}

class AuthManager {
    private SecurityCore $security;

    public function __construct(SecurityCore $security) {
        $this->security = $security;
    }

    public function authenticate(array $credentials): array {
        return $this->security->validateOperation(function() use ($credentials) {
            $user = DB::table('users')->where('email', $credentials['email'])->first();
            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                throw new AuthException('Invalid credentials');
            }
            $token = bin2hex(random_bytes(32));
            Cache::put("auth_token_{$token}", $user->id, 3600);
            return ['token' => $token, 'user' => $user];
        }, ['context' => 'auth']);
    }

    public function validateAccess(string $token, string $permission): bool {
        $userId = Cache::get("auth_token_{$token}");
        if (!$userId) return false;
        return DB::table('user_permissions')
            ->where('user_id', $userId)
            ->where('permission', $permission)
            ->exists();
    }
}

class ContentManager {
    private SecurityCore $security;

    public function __construct(SecurityCore $security) {
        $this->security = $security;
    }

    public function createContent(array $data, string $token): array {
        return $this->security->validateOperation(function() use ($data) {
            $contentId = DB::table('content')->insertGetId([
                'title' => $data['title'],
                'content' => $data['content'],
                'created_at' => now()
            ]);
            
            if (isset($data['media'])) {
                foreach ($data['media'] as $file) {
                    $path = Storage::put('content', $file);
                    DB::table('content_media')->insert([
                        'content_id' => $contentId,
                        'path' => $path
                    ]);
                }
            }
            
            return DB::table('content')->find($contentId);
        }, ['context' => 'content_create']);
    }

    public function updateContent(int $id, array $data, string $token): array {
        return $this->security->validateOperation(function() use ($id, $data) {
            DB::table('content')
                ->where('id', $id)
                ->update([
                    'title' => $data['title'],
                    'content' => $data['content'],
                    'updated_at' => now()
                ]);
            
            if (isset($data['media'])) {
                foreach ($data['media'] as $file) {
                    $path = Storage::put('content', $file);
                    DB::table('content_media')->insert([
                        'content_id' => $id,
                        'path' => $path
                    ]);
                }
            }
            
            return DB::table('content')->find($id);
        }, ['context' => 'content_update']);
    }
}

class TemplateManager {
    private SecurityCore $security;
    private array $config;

    public function __construct(SecurityCore $security, array $config) {
        $this->security = $security;
        $this->config = $config;
    }

    public function render(string $template, array $data, string $token): string {
        return $this->security->validateOperation(function() use ($template, $data) {
            $key = "template_" . md5($template . serialize($data));
            
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
        $path = $this->config['template_path'] . '/' . $template;
        if (!Storage::exists($path)) {
            throw new TemplateException('Template not found');
        }
        return Storage::get($path);
    }

    private function processTemplate(string $content, array $data): string {
        foreach ($data as $key => $value) {
            $content = str_replace("{{$key}}", htmlspecialchars($value), $content);
        }
        return $content;
    }
}

class InfrastructureManager {
    private SecurityCore $security;
    private array $metrics = [];

    public function __construct(SecurityCore $security) {
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
            $status = [
                'database' => DB::select('SELECT 1')[0] ? true : false,
                'cache' => Cache::set('health_check', true),
                'storage' => Storage::put('health_check', 'test')
            ];
            return $status;
        }, ['context' => 'health_check']);
    }

    public function getMetrics(): array {
        return $this->metrics;
    }
}
