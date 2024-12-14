<?php
namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Hash, Auth};
use App\Exceptions\SecurityException;

class AuthManager {
    private $maxAttempts = 3;
    private $lockoutTime = 900;

    public function authenticate(array $credentials) {
        return DB::transaction(function() use ($credentials) {
            if ($this->isBlocked(request()->ip())) {
                throw new SecurityException('Account locked');
            }

            if (!$user = $this->validateCredentials($credentials)) {
                $this->handleFailedAttempt();
                return false;
            }

            $this->clearAttempts();
            $this->createSession($user);
            return $user;
        });
    }

    private function validateCredentials($credentials) {
        $user = DB::table('users')
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return false;
        }

        return $user;
    }

    private function handleFailedAttempt() {
        $key = $this->getBlockKey();
        $attempts = Cache::increment($key, 1, 0);

        if ($attempts >= $this->maxAttempts) {
            Cache::put($key.':blocked', true, $this->lockoutTime);
        }
    }

    private function isBlocked($ip) {
        return Cache::get($this->getBlockKey().':blocked', false);
    }

    private function getBlockKey() {
        return 'auth:attempts:'.request()->ip();
    }

    private function clearAttempts() {
        Cache::forget($this->getBlockKey());
        Cache::forget($this->getBlockKey().':blocked');
    }

    private function createSession($user) {
        $token = Hash::make(uniqid().time());
        Cache::put('session:'.$token, $user->id, 3600);
        return $token;
    }
}

class ContentManager {
    private $validators = [
        'title' => 'required|string|max:200',
        'content' => 'required|string',
        'status' => 'required|in:draft,published'
    ];

    public function createContent(array $data) {
        return DB::transaction(function() use ($data) {
            $this->validateData($data);
            
            $id = DB::table('contents')->insertGetId([
                'title' => $data['title'],
                'content' => $data['content'],
                'status' => $data['status'],
                'user_id' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->invalidateCache($id);
            return $id;
        });
    }

    public function updateContent(int $id, array $data) {
        return DB::transaction(function() use ($id, $data) {
            $this->validateData($data);
            
            $updated = DB::table('contents')
                ->where('id', $id)
                ->update([
                    'title' => $data['title'],
                    'content' => $data['content'],
                    'status' => $data['status'],
                    'updated_at' => now()
                ]);

            $this->invalidateCache($id);
            return $updated;
        });
    }

    public function getContent(int $id) {
        return Cache::remember('content:'.$id, 3600, function() use ($id) {
            return DB::table('contents')->find($id);
        });
    }

    private function validateData(array $data) {
        foreach ($this->validators as $field => $rules) {
            if (!$this->validateField($data[$field] ?? null, $rules)) {
                throw new ValidationException("Invalid $field");
            }
        }
    }

    private function validateField($value, $rules) {
        foreach (explode('|', $rules) as $rule) {
            if (!$this->checkRule($value, $rule)) {
                return false;
            }
        }
        return true;
    }

    private function checkRule($value, $rule) {
        return match($rule) {
            'required' => !empty($value),
            'string' => is_string($value),
            'max:200' => strlen($value) <= 200,
            'in:draft,published' => in_array($value, ['draft', 'published']),
            default => true
        };
    }

    private function invalidateCache($id) {
        Cache::forget('content:'.$id);
    }
}

class SecurityManager {
    private $encryptionKey;

    public function __construct() {
        $this->encryptionKey = config('app.key');
    }

    public function encrypt($data) {
        return openssl_encrypt(
            serialize($data),
            'AES-256-CBC',
            $this->encryptionKey,
            0,
            substr($this->encryptionKey, 0, 16)
        );
    }

    public function decrypt($data) {
        return unserialize(openssl_decrypt(
            $data,
            'AES-256-CBC',
            $this->encryptionKey,
            0,
            substr($this->encryptionKey, 0, 16)
        ));
    }

    public function hash($data) {
        return hash_hmac('sha256', $data, $this->encryptionKey);
    }

    public function verifyHash($data, $hash) {
        return hash_equals($this->hash($data), $hash);
    }
}

class TemplateEngine {
    private $cache;
    private $security;

    public function __construct(SecurityManager $security) {
        $this->security = $security;
        $this->cache = new Cache();
    }

    public function render($template, array $data = []) {
        $compiled = $this->compile($template);
        return $this->evaluate($compiled, $data);
    }

    private function compile($template) {
        return $this->cache->remember('template:'.md5($template), 3600, function() use ($template) {
            $template = $this->security->encrypt($template);
            return preg_replace_callback('/\{\{(.+?)\}\}/', function($matches) {
                return '<?php echo htmlspecialchars('.trim($matches[1]).'); ?>';
            }, $template);
        });
    }

    private function evaluate($compiled, array $data) {
        extract($data);
        ob_start();
        eval('?>'.$this->security->decrypt($compiled));
        return ob_get_clean();
    }
}
