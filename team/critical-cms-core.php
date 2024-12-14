<?php

namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, File, Hash};
use App\Exceptions\{SecurityException, CMSException};

class CoreSystem
{
    protected array $config;
    protected string $basePath;

    public function __construct(array $config, string $basePath)
    {
        $this->config = $config;
        $this->basePath = $basePath;
    }

    // Auth System
    public function authenticate(array $credentials): array
    {
        return DB::transaction(function() use ($credentials) {
            $user = DB::table('users')
                ->where('email', $credentials['email'])
                ->first();

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                throw new SecurityException('Invalid credentials');
            }

            $token = $this->generateToken($user);
            $this->saveSession($user, $token);

            return ['user' => $user, 'token' => $token];
        });
    }

    protected function generateToken($user): string
    {
        $payload = json_encode([
            'id' => $user->id,
            'exp' => time() + 3600,
            'type' => 'access'
        ]);
        return base64_encode(
            openssl_encrypt($payload, 'AES-256-CBC', $this->config['key'])
        );
    }

    // Content Management
    public function createContent(array $data, string $token): array
    {
        $this->verifyToken($token);

        return DB::transaction(function() use ($data) {
            $id = DB::table('content')->insertGetId([
                'title' => $data['title'],
                'body' => $data['body'],
                'status' => $data['status'] ?? 'draft',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            Cache::tags(['content'])->flush();
            return $this->getContent($id);
        });
    }

    public function updateContent(int $id, array $data, string $token): array
    {
        $this->verifyToken($token);

        return DB::transaction(function() use ($id, $data) {
            $updated = DB::table('content')
                ->where('id', $id)
                ->update([
                    'title' => $data['title'],
                    'body' => $data['body'],
                    'status' => $data['status'],
                    'updated_at' => now()
                ]);

            if (!$updated) {
                throw new CMSException('Content not found');
            }

            Cache::tags(['content'])->flush();
            return $this->getContent($id);
        });
    }

    // Template Management
    public function renderTemplate(string $template, array $data, string $token): string
    {
        $this->verifyToken($token);

        $path = $this->basePath . '/templates/' . $template;
        
        if (!File::exists($path)) {
            throw new CMSException('Template not found');
        }

        return Cache::remember(
            "template:$template",
            3600,
            function() use ($path, $data) {
                $content = File::get($path);
                return $this->processTemplate($content, $this->sanitizeData($data));
            }
        );
    }

    protected function processTemplate(string $content, array $data): string
    {
        $content = preg_replace('/\{\{(.*?)\}\}/', '<?php echo htmlspecialchars($1); ?>', $content);
        $content = preg_replace('/@if\((.*?)\)/', '<?php if($1): ?>', $content);
        $content = preg_replace('/@endif/', '<?php endif; ?>', $content);
        ob_start();
        extract($data);
        eval('?>' . $content);
        return ob_get_clean();
    }

    // Infrastructure
    protected function verifyToken(string $token): bool
    {
        try {
            $payload = openssl_decrypt(
                base64_decode($token),
                'AES-256-CBC',
                $this->config['key']
            );
            $data = json_decode($payload, true);
            
            if (!isset($data['exp']) || $data['exp'] < time()) {
                throw new SecurityException('Token expired');
            }

            return true;
        } catch (\Exception $e) {
            throw new SecurityException('Invalid token');
        }
    }

    protected function saveSession($user, string $token): void
    {
        Cache::put(
            "session:{$user->id}",
            [
                'token' => $token,
                'ip' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'last_activity' => time()
            ],
            3600
        );
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    public function getContent(int $id): array
    {
        return Cache::remember("content:$id", 3600, function() use ($id) {
            $content = DB::table('content')->find($id);
            if (!$content) {
                throw new CMSException('Content not found');
            }
            return (array)$content;
        });
    }

    public function query(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }
}

class QueryBuilder
{
    private string $table;
    private array $where = [];
    private array $select = ['*'];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function where(string $column, $operator, $value = null): self
    {
        $this->where[] = [$column, $operator, $value ?? $operator];
        return $this;
    }

    public function get(): array
    {
        $query = DB::table($this->table)->select($this->select);
        foreach ($this->where as [$column, $operator, $value]) {
            $query->where($column, $operator, $value);
        }
        return $query->get()->all();
    }
}
