<?php
namespace App\Core;

class CriticalCmsSystem 
{
    protected function boot(): void 
    {
        $this->bootSecurityLayer();
        $this->bootInfrastructure();
        $this->bootDataLayer();
        $this->bootControllers();
    }

    // Security Layer
    class SecurityManager {
        protected TokenService $tokens;
        protected EncryptionService $crypto;

        public function authenticate(array $credentials): AuthResult {
            return DB::transaction(function() use ($credentials) {
                $user = User::where('email', $credentials['email'])->first();
                if (!$user || !$this->crypto->verify($credentials['password'], $user->password)) {
                    throw new AuthException('Invalid credentials');
                }
                return new AuthResult($user, $this->tokens->generate($user));
            });
        }
    }

    // Core Infrastructure 
    class CoreManager {
        protected CacheManager $cache;
        protected LogManager $logs;

        public function cacheOperation(string $key, callable $operation) {
            return $this->cache->remember($key, fn() => DB::transaction($operation));
        }
    }

    // Data Access Layer
    class ContentManager {
        protected Repository $repo;
        protected ValidationService $validator;

        public function store(array $data): Content {
            $validated = $this->validator->validate($data, [
                'title' => 'required|string|max:255',
                'body' => 'required',
                'status' => 'in:draft,published'
            ]);

            return DB::transaction(function() use ($validated) {
                $content = $this->repo->create($validated);
                Cache::tags('content')->flush();
                return $content;
            });
        }

        public function update(int $id, array $data): Content {
            $validated = $this->validator->validate($data);
            
            return DB::transaction(function() use ($id, $validated) {
                $content = $this->repo->findOrFail($id);
                $content->update($validated);
                Cache::tags(['content', $id])->flush();
                return $content->fresh();
            });
        }
    }

    // Template System
    class TemplateManager {
        protected Repository $templates;
        protected ValidationService $validator;

        public function render(string $name, array $data = []): string {
            return Cache::remember("template.$name", 3600, function() use ($name, $data) {
                $template = $this->templates->findByName($name);
                $validated = $this->validator->validate($data);
                return view($template->path, $validated)->render();
            });
        }
    }

    // Models
    class User extends Model {
        protected $guarded = ['id'];
        protected $hidden = ['password'];

        protected static function boot() {
            parent::boot();
            static::creating(function($user) {
                $user->password = bcrypt($user->password);
            });
        }
    }

    class Content extends Model {
        protected $guarded = ['id'];
        protected $casts = ['metadata' => 'array', 'published_at' => 'datetime'];
    }

    class Template extends Model {
        protected $guarded = ['id'];
        protected $casts = ['config' => 'array'];
    }

    // API Controllers
    class ApiController extends Controller {
        public function auth(Request $request) {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required'
            ]);

            try {
                $result = app(SecurityManager::class)->authenticate($credentials);
                return response()->json($result);
            } catch (AuthException $e) {
                return response()->json(['error' => 'Invalid credentials'], 401);
            }
        }

        public function storeContent(Request $request) {
            try {
                $content = app(ContentManager::class)->store($request->all());
                return response()->json($content, 201);
            } catch (ValidationException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
        }

        public function renderTemplate(string $name, Request $request) {
            try {
                $html = app(TemplateManager::class)->render($name, $request->all());
                return response()->json(['html' => $html]);
            } catch (\Exception $e) {
                return response()->json(['error' => 'Template error'], 500);
            }
        }
    }

    // Database Schema
    protected function createSchema(): void {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->text('body');
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('path');
            $table->json('config')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
