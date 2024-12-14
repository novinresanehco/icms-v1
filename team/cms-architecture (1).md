# ICMS Core Architecture & Implementation Guide

## 1. Core System Architecture

### 1.1 Service Layer Implementation
```php
namespace App\Core\Services;

abstract class BaseService
{
    protected Repository $repository;
    protected CacheManager $cache;
    protected EventDispatcher $events;
    
    public function __construct(
        Repository $repository,
        CacheManager $cache,
        EventDispatcher $events
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->events = $events;
    }

    protected function beginTransaction(): void 
    {
        DB::beginTransaction();
    }

    protected function cached(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        return $this->cache->remember($key, $callback, $ttl);
    }
}
```

### 1.2 Repository Pattern Implementation
```php
namespace App\Core\Repositories;

abstract class BaseRepository
{
    protected Model $model;
    protected array $with = [];
    protected array $criteria = [];

    abstract public function model(): string;

    public function __construct()
    {
        $this->model = app($this->model());
    }

    public function find(int $id, array $columns = ['*']): ?Model
    {
        return $this->prepareCriteria()
            ->with($this->with)
            ->find($id, $columns);
    }

    protected function prepareCriteria(): Builder
    {
        $query = $this->model->newQuery();
        
        foreach ($this->criteria as $criterion) {
            $query = $criterion->apply($query);
        }
        
        return $query;
    }
}
```

### 1.3 Cache Implementation
```php
namespace App\Core\Cache;

class CacheManager
{
    protected array $tags = [];
    protected int $defaultTtl;

    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $cache = empty($this->tags) ? Cache::store() : Cache::tags($this->tags);
        
        return $cache->remember($key, $ttl, $callback);
    }
}
```

## 2. Critical System Components

### 2.1 Tag System (In Progress)
```php
namespace App\Core\Tag;

interface TagServiceInterface
{
    public function create(array $data): Tag;
    public function update(int $id, array $data): Tag;
    public function delete(int $id): bool;
    public function attachToContent(int $contentId, array $tagIds): void;
}

class TagService extends BaseService implements TagServiceInterface
{
    public function create(array $data): Tag
    {
        $this->beginTransaction();
        
        try {
            $tag = $this->repository->create($data);
            $this->cache->tags(['tags'])->flush();
            
            DB::commit();
            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagCreationException($e->getMessage());
        }
    }
}
```

### 2.2 Media System (In Progress)
```php
namespace App\Core\Media;

interface MediaServiceInterface
{
    public function upload(UploadedFile $file): Media;
    public function process(Media $media): void;
    public function optimize(Media $media): void;
}

class MediaService extends BaseService implements MediaServiceInterface
{
    protected array $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];
    protected int $maxFileSize = 10240; // 10MB

    public function upload(UploadedFile $file): Media
    {
        $this->validateFile($file);
        
        $this->beginTransaction();
        
        try {
            $media = $this->repository->create([
                'name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $this->storeFile($file)
            ]);
            
            DB::commit();
            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaUploadException($e->getMessage());
        }
    }
}
```

## 3. Security Implementation

### 3.1 Input Validation
```php
namespace App\Core\Validation;

abstract class BaseValidator
{
    protected array $rules = [];
    protected ValidationFactory $factory;

    public function validate(array $data): array
    {
        $validator = $this->factory->make($data, $this->rules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        
        return $validator->validated();
    }
}
```

### 3.2 Authentication Framework
```php
namespace App\Core\Auth;

class AuthenticationManager
{
    protected AuthProvider $provider;
    protected SessionManager $session;
    protected SecurityLogger $logger;

    public function authenticate(array $credentials): bool
    {
        try {
            $user = $this->provider->validateCredentials($credentials);
            
            if (!$user) {
                $this->logger->logFailedAttempt($credentials);
                return false;
            }
            
            $this->session->create($user);
            $this->logger->logSuccessfulLogin($user);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->logError('authentication_error', $e->getMessage());
            throw new AuthenticationException($e->getMessage());
        }
    }
}
```

## 4. Performance Optimization

### 4.1 Query Optimization
```php
namespace App\Core\Database;

trait QueryOptimizer
{
    protected function optimizeSelect(Builder $query): Builder
    {
        return $query->select($this->getRequiredColumns())
                    ->with($this->getRequiredRelations());
    }

    protected function getCacheKey(array $params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            md5(serialize($params)),
            now()->format('Y-m-d')
        );
    }
}
```

### 4.2 Caching Strategy
```php
namespace App\Core\Cache;

class QueryCache
{
    protected CacheManager $cache;
    protected QueryAnalyzer $analyzer;

    public function cacheQuery(Builder $query, string $key, ?int $ttl = null): mixed
    {
        if ($this->analyzer->shouldCache($query)) {
            return $this->cache->remember($key, function() use ($query) {
                return $query->get();
            }, $ttl);
        }
        
        return $query->get();
    }
}
```

## 5. Testing Framework

### 5.1 Base Test Case
```php
namespace Tests;

abstract class TestCase extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollbackTransaction();
        parent::tearDown();
    }

    protected function createTestUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
```

## 6. Implementation Guidelines

1. All services must extend BaseService
2. All repositories must implement BaseRepository
3. Cache must be used for frequently accessed data
4. Input validation required for all user inputs
5. Comprehensive testing required for all components
6. Security measures must be implemented at all layers
7. Performance optimization is mandatory

## 7. Security Checklist

- [ ] Input validation implemented
- [ ] XSS prevention in place
- [ ] CSRF protection enabled
- [ ] SQL injection prevention implemented
- [ ] Proper authentication system
- [ ] Authorization checks in place
- [ ] Data encryption for sensitive data
- [ ] Secure file handling
- [ ] Rate limiting implemented
- [ ] Security headers configured

## 8. Performance Checklist

- [ ] Query optimization implemented
- [ ] Caching strategy in place
- [ ] Asset optimization configured
- [ ] Database indexes created
- [ ] N+1 query problems resolved
- [ ] Lazy loading implemented
- [ ] Queue system for long running tasks
- [ ] Response compression enabled
- [ ] CDN integration configured
- [ ] API rate limiting implemented
