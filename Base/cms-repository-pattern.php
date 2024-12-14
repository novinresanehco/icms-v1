<?php

namespace App\Repositories\Contracts;

interface RepositoryInterface
{
    public function all(array $columns = ['*']);
    public function paginate(int $perPage = 15, array $columns = ['*']);
    public function create(array $data);
    public function update(array $data, $id);
    public function delete($id);
    public function find($id, array $columns = ['*']);
    public function findBy(string $field, $value, array $columns = ['*']);
    public function findWhere(array $criteria, array $columns = ['*']);
}

namespace App\Repositories\Contracts;

interface CacheableInterface
{
    public function getCacheKey(string $method, array $params = []): string;
    public function getCacheTTL(): int;
    public function clearCache(): bool;
}

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use App\Repositories\Contracts\CacheableInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Core\Database\Performance\DatabasePerformanceManager;

abstract class BaseRepository implements RepositoryInterface, CacheableInterface
{
    protected $model;
    protected $performanceManager;
    protected $cache;
    protected $cacheTTL = 3600; // 1 hour default
    
    public function __construct(DatabasePerformanceManager $performanceManager)
    {
        $this->performanceManager = $performanceManager;
        $this->makeModel();
    }

    abstract protected function model(): string;

    protected function makeModel(): Model
    {
        $model = Container::getInstance()->make($this->model());
        
        if (!$model instanceof Model) {
            throw new \Exception("Class {$this->model()} must be an instance of Illuminate\\Database\\Eloquent\\Model");
        }
        
        return $this->model = $model;
    }

    public function all(array $columns = ['*'])
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['columns' => $columns]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($columns) {
            $this->performanceManager->startMeasurement();
            $result = $this->model->select($columns)->get();
            $this->performanceManager->endMeasurement();
            
            return $result;
        });
    }

    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        $this->performanceManager->startMeasurement();
        $result = $this->model->select($columns)->paginate($perPage);
        $this->performanceManager->endMeasurement();
        
        return $result;
    }

    public function create(array $data)
    {
        $this->performanceManager->startMeasurement();
        $model = $this->model->create($data);
        $this->performanceManager->endMeasurement();
        
        $this->clearCache();
        
        return $model;
    }

    public function update(array $data, $id)
    {
        $this->performanceManager->startMeasurement();
        
        $model = $this->find($id);
        if ($model) {
            $model->update($data);
        }
        
        $this->performanceManager->endMeasurement();
        $this->clearCache();
        
        return $model;
    }

    public function delete($id): bool
    {
        $this->performanceManager->startMeasurement();
        
        $model = $this->find($id);
        $result = false;
        
        if ($model) {
            $result = $model->delete();
        }
        
        $this->performanceManager->endMeasurement();
        $this->clearCache();
        
        return $result;
    }

    public function find($id, array $columns = ['*'])
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['id' => $id, 'columns' => $columns]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($id, $columns) {
            $this->performanceManager->startMeasurement();
            $result = $this->model->select($columns)->find($id);
            $this->performanceManager->endMeasurement();
            
            return $result;
        });
    }

    public function findBy(string $field, $value, array $columns = ['*'])
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['field' => $field, 'value' => $value, 'columns' => $columns]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($field, $value, $columns) {
            $this->performanceManager->startMeasurement();
            $result = $this->model->select($columns)->where($field, $value)->first();
            $this->performanceManager->endMeasurement();
            
            return $result;
        });
    }

    public function findWhere(array $criteria, array $columns = ['*'])
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['criteria' => $criteria, 'columns' => $columns]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($criteria, $columns) {
            $this->performanceManager->startMeasurement();
            
            $query = $this->model->select($columns);
            foreach ($criteria as $key => $value) {
                if (is_array($value)) {
                    list($operator, $val) = $value;
                    $query->where($key, $operator, $val);
                } else {
                    $query->where($key, '=', $value);
                }
            }
            
            $result = $query->get();
            $this->performanceManager->endMeasurement();
            
            return $result;
        });
    }

    public function getCacheKey(string $method, array $params = []): string
    {
        $params = array_merge([
            'model' => get_class($this->model),
            'method' => $method
        ], $params);
        
        return 'repository.' . md5(serialize($params));
    }

    public function getCacheTTL(): int
    {
        return $this->cacheTTL;
    }

    public function clearCache(): bool
    {
        return Cache::tags($this->getCacheTags())->flush();
    }

    protected function getCacheTags(): array
    {
        return [get_class($this->model)];
    }

    protected function newQuery(): Builder
    {
        return $this->model->newQuery();
    }
}

namespace App\Repositories;

use App\Models\Content;
use App\Core\Database\Performance\DatabasePerformanceManager;

class ContentRepository extends BaseRepository
{
    protected $cacheTTL = 1800; // 30 minutes
    
    public function __construct(DatabasePerformanceManager $performanceManager)
    {
        parent::__construct($performanceManager);
    }

    protected function model(): string
    {
        return Content::class;
    }

    public function findPublished(array $columns = ['*'])
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['columns' => $columns]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($columns) {
            return $this->model
                ->select($columns)
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get();
        });
    }

    public function findBySlug(string $slug, array $columns = ['*'])
    {
        return $this->findBy('slug', $slug, $columns);
    }

    public function findByCategory(int $categoryId, array $columns = ['*'])
    {
        return $this->findWhere(['category_id' => $categoryId], $columns);
    }

    public function bulkInsert(array $records): bool
    {
        $this->performanceManager->startMeasurement();
        
        $result = $this->model->insert($records);
        
        $this->performanceManager->endMeasurement();
        $this->clearCache();
        
        return $result;
    }

    public function bulkUpdate(array $values, array $conditions): int
    {
        $this->performanceManager->startMeasurement();
        
        $query = $this->newQuery();
        foreach ($conditions as $field => $condition) {
            if (is_array($condition)) {
                list($operator, $value) = $condition;
                $query->where($field, $operator, $value);
            } else {
                $query->where($field, '=', $condition);
            }
        }
        
        $affected = $query->update($values);
        
        $this->performanceManager->endMeasurement();
        $this->clearCache();
        
        return $affected;
    }

    public function searchContent(string $term, array $columns = ['*'])
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['term' => $term, 'columns' => $columns]);
        
        return Cache::remember($cacheKey, $this->getCacheTTL(), function () use ($term, $columns) {
            return $this->model
                ->select($columns)
                ->where('title', 'LIKE', "%{$term}%")
                ->orWhere('content', 'LIKE', "%{$term}%")
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get();
        });
    }
}
