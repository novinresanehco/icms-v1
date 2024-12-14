<?php

namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Log};

// Critical System Entry Point
class CMSCore
{
    protected SecurityManager $security;
    protected ContentManager $content;
    protected AuthenticationService $auth;
    protected TemplateManager $template;
    protected CacheManager $cache;
    protected LogManager $logger;

    public function __construct(
        SecurityManager $security,
        ContentManager $content,
        AuthenticationService $auth,
        TemplateManager $template,
        CacheManager $cache,
        LogManager $logger
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->auth = $auth;
        $this->template = $template;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function authenticate(array $credentials): array
    {
        try {
            return $this->auth->authenticate($credentials);
        } catch (\Exception $e) {
            $this->logger->critical('Authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function createContent(array $data, array $media = []): Content
    {
        return DB::transaction(function() use ($data, $media) {
            try {
                $content = $this->content->createContent($data);
                
                if (!empty($media)) {
                    $content->attachMedia($media);
                }

                $this->cache->tags(['content'])->flush();
                return $content;

            } catch (\Exception $e) {
                $this->logger->error('Content creation failed', [
                    'error' => $e->getMessage(),
                    'data' => $data
                ]);
                throw $e;
            }
        });
    }

    public function renderTemplate(string $name, array $data = []): string
    {
        return $this->cache->remember("template.$name", function() use ($name, $data) {
            return $this->template->render($name, $data);
        });
    }

    public function validatePermissions(User $user, string $action): bool
    {
        return $this->security->validateAccess($user, $action);
    }
}

// Bootstrap Core CMS
class CMSApplication
{
    public static function boot(): CMSCore
    {
        $security = new SecurityManager(
            new AuthenticationService(
                new UserRepository,
                new TokenService(config('security.auth.token_lifetime')),
                new RateLimiter(
                    config('security.auth.max_attempts'),
                    config('security.auth.decay_minutes')
                )
            ),
            new ValidationService,
            new AuditLogger
        );

        $cache = new CacheManager(
            Cache::store(config('cache.default')),
            config('cache.prefix'),
            config('cache.ttl')
        );

        $content = new ContentManager(
            new ContentRepository,
            new CategoryRepository,
            new MediaRepository,
            $security
        );

        $template = new TemplateManager(
            new ThemeRepository,
            $security,
            $cache
        );

        $logger = new LogManager(
            Log::channel(config('logging.default')),
            config('logging.channels')
        );

        return new CMSCore(
            $security,
            $content,
            $security->getAuthService(),
            $template,
            $cache,
            $logger
        );
    }

    public static function setupDatabase(): void
    {
        DB::transaction(function() {
            Schema::create('users', function($table) {
                $table->id();
                $table->string('email')->unique();
                $table->string('password_hash');
                $table->string('password_salt');
                $table->string('role');
                $table->string('status');
                $table->timestamps();
            });

            Schema::create('content', function($table) {
                $table->id();
                $table->string('title');
                $table->string('slug')->unique();
                $table->text('content');
                $table->string('status');
                $table->foreignId('user_id');
                $table->timestamps();
                $table->softDeletes();
            });

            Schema::create('categories', function($table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->foreignId('parent_id')->nullable();
                $table->timestamps();
            });

            Schema::create('media', function($table) {
                $table->id();
                $table->string('name');
                $table->string('path');
                $table->string('mime_type');
                $table->integer('size');
                $table->foreignId('user_id');
                $table->morphs('mediable');
                $table->timestamps();
            });

            Schema::create('themes', function($table) {
                $table->id();
                $table->string('name');
                $table->string('path');
                $table->boolean('active');
                $table->timestamps();
            });
        });
    }
}
