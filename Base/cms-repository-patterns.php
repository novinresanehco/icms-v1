<?php

namespace App\Repositories;

use App\Models\Category;
use App\Core\Database\Performance\DatabasePerformanceManager;

class CategoryRepository extends BaseRepository
{
    protected $cacheTTL = 3600; // 1 hour cache for categories
    
    protected function model(): string
    {
        return Category::class;
    }

    public function findBySlug(string $slug)
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['slug' => $slug]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($slug) {
            return $this->model->where('slug', $slug)->first();
        });
    }

    public function getWithContentCount()
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () {
            return $this->model
                ->withCount('contents')
                ->orderBy('name')
                ->get();
        });
    }

    public function getNavigationTree()
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () {
            return $this->model
                ->whereNull('parent_id')
                ->with(['children' => function ($query) {
                    $query->orderBy('position');
                }])
                ->orderBy('position')
                ->get();
        });
    }
}

namespace App\Repositories;

use App\Models\Tag;
use App\Core\Database\Performance\DatabasePerformanceManager;

class TagRepository extends BaseRepository
{
    protected $cacheTTL = 3600;
    
    protected function model(): string
    {
        return Tag::class;
    }

    public function findPopular(int $limit = 10)
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['limit' => $limit]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($limit) {
            return $this->model
                ->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get();
        });
    }

    public function findOrCreateMultiple(array $tagNames): array
    {
        $this->performanceManager->startMeasurement();
        
        $tags = [];
        foreach ($tagNames as $name) {
            $tags[] = $this->model->firstOrCreate(
                ['name' => trim($name)],
                ['slug' => \Str::slug(trim($name))]
            );
        }
        
        $this->performanceManager->endMeasurement();
        $this->clearCache();
        
        return $tags;
    }
}

namespace App\Repositories;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaRepository extends BaseRepository
{
    protected $cacheTTL = 1800; // 30 minutes
    
    protected function model(): string
    {
        return Media::class;
    }

    public function upload(UploadedFile $file, array $attributes = [])
    {
        $this->performanceManager->startMeasurement();
        
        $path = $file->store('media', 'public');
        
        $media = $this->create(array_merge([
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize()
        ], $attributes));
        
        $this->performanceManager->endMeasurement();
        
        return $media;
    }

    public function findByType(string $type)
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['type' => $type]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($type) {
            return $this->model
                ->where('mime_type', 'LIKE', $type . '/%')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    public function delete($id): bool
    {
        $media = $this->find($id);
        
        if ($media) {
            Storage::disk('public')->delete($media->path);
            return parent::delete($id);
        }
        
        return false;
    }
}

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository
{
    protected $cacheTTL = 1800;
    
    protected function model(): string
    {
        return User::class;
    }

    public function create(array $data)
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        
        return parent::create($data);
    }

    public function update(array $data, $id)
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        
        return parent::update($data, $id);
    }

    public function findByRole(string $role)
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['role' => $role]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($role) {
            return $this->model
                ->whereHas('roles', function ($query) use ($role) {
                    $query->where('name', $role);
                })
                ->get();
        });
    }

    public function updateLastLogin($id)
    {
        return $this->update(['last_login_at' => now()], $id);
    }
}

namespace App\Core\Database\Performance;

class DatabasePerformanceManager
{
    protected $measurements = [];
    protected $startTime;
    protected $startMemory;
    
    public function startMeasurement()
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }
    
    public function endMeasurement()
    {
        $duration = (microtime(true) - $this->startTime) * 1000; // Convert to milliseconds
        $memoryUsed = memory_get_usage(true) - $this->startMemory;
        
        $this->measurements[] = [
            'duration' => $duration,
            'memory' => $memoryUsed,
            'timestamp' => now(),
            'queries' => \DB::getQueryLog()
        ];
        
        if ($duration > config('database.slow_query_threshold', 100)) {
            $this->logSlowQuery($duration);
        }
    }
    
    protected function logSlowQuery($duration)
    {
        \Log::warning("Slow query detected: {$duration}ms", [
            'queries' => end($this->measurements)['queries']
        ]);
    }
    
    public function getPerformanceMetrics()
    {
        return [
            'average_duration' => collect($this->measurements)->average('duration'),
            'max_duration' => collect($this->measurements)->max('duration'),
            'total_memory' => collect($this->measurements)->sum('memory'),
            'query_count' => collect($this->measurements)->sum(function ($m) {
                return count($m['queries']);
            })
        ];
    }
}
