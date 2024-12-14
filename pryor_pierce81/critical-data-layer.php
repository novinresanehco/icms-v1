<?php
namespace App\Core\Data;

class User extends Model implements Authenticatable 
{
    protected $guarded = ['id'];
    protected $hidden = ['password'];
    
    public function content() {
        return $this->hasMany(Content::class);
    }
}

class Content extends Model 
{
    protected $guarded = ['id'];
    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array'
    ];
    
    public function user() {
        return $this->belongsTo(User::class);
    }
}

class Template extends Model 
{
    protected $guarded = ['id'];
    protected $casts = ['config' => 'array'];
}

class Migration extends Migration 
{
    public function up(): void 
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('remember_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('content', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'published_at']);
        });

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('path');
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }
}

class UserRepository 
{
    protected User $model;
    protected HashService $hash;

    public function create(array $data): User 
    {
        return DB::transaction(function() use ($data) {
            $data['password'] = $this->hash->make($data['password']);
            return $this->model->create($data);
        });
    }

    public function findByEmail(string $email): ?User 
    {
        return $this->model->where('email', $email)->first();
    }
}

class ContentRepository 
{
    protected Content $model;

    public function findPublished(int $limit = 10) 
    {
        return Cache::tags(['content'])->remember('content.published', 3600, function() use ($limit) {
            return $this->model
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->latest('published_at')
                ->limit($limit)
                ->get();
        });
    }

    public function create(array $data): Content 
    {
        return DB::transaction(function() use ($data) {
            $content = $this->model->create($data);
            Cache::tags(['content'])->flush();
            return $content;
        });
    }
}

class TemplateRepository 
{
    protected Template $model;
    protected CacheManager $cache;

    public function findByName(string $name): ?Template 
    {
        return $this->cache->remember("template.{$name}", function() use ($name) {
            return $this->model->where('name', $name)->firstOrFail();
        });
    }

    public function create(array $data): Template 
    {
        return DB::transaction(function() use ($data) {
            $template = $this->model->create($data);
            $this->cache->tags(['templates'])->flush();
            return $template;
        });
    }
}
