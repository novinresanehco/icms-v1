// File: app/Core/Repository/BaseRepository.php
<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

abstract class BaseRepository
{
    protected Model $model;
    protected array $criteria = [];
    protected array $scopes = [];
    
    abstract public function model(): string;
    
    public function __construct()
    {
        $this->model = app($this->model());
    }

    public function find(int $id): ?Model
    {
        return $this->prepareCriteria()->find($id);
    }
    
    public function all(array $columns = ['*']): Collection
    {
        return $this->prepareCriteria()->get($columns);
    }
    
    public function create(array $attributes): Model
    {
        $model = $this->model->newInstance($attributes);
        $model->save();
        
        return $model;
    }
    
    public function update(int $id, array $attributes): Model
    {
        $model = $this->find($id);
        $model->update($attributes);
        
        return $model;
    }
    
    public function delete(int $id): bool
    {
        return $this->find($id)->delete();
    }
    
    public function scope(string $scope, array $parameters = []): self
    {
        $this->scopes[$scope] = $parameters;
        return $this;
    }
    
    protected function prepareCriteria(): Model
    {
        $query = $this->model->newQuery();
        
        foreach ($this->criteria as $criterion) {
            $query = $criterion->apply($query);
        }
        
        foreach ($this->scopes as $scope => $parameters) {
            $query = $query->$scope(...$parameters);
        }
        
        return $query;
    }
}

// File: app/Core/Repository/Interfaces/RepositoryInterface.php
<?php

namespace App\Core\Repository\Interfaces;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface RepositoryInterface
{
    public function find(int $id): ?Model;
    public function all(array $columns = ['*']): Collection;
    public function create(array $attributes): Model;
    public function update(int $id, array $attributes): Model;
    public function delete(int $id): bool;
}

// File: app/Core/Repository/Criteria/Interface/CriteriaInterface.php
<?php

namespace App\Core\Repository\Criteria\Interface;

use Illuminate\Database\Eloquent\Builder;

interface CriteriaInterface
{
    public function apply(Builder $query): Builder;
}

// File: app/Core/Repository/Criteria/WithTrashedCriteria.php
<?php

namespace App\Core\Repository\Criteria;

use App\Core\Repository\Criteria\Interface\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

class WithTrashedCriteria implements CriteriaInterface
{
    public function apply(Builder $query): Builder
    {
        return $query->withTrashed();
    }
}

// File: app/Core/Repository/Criteria/OrderByCriteria.php
<?php

namespace App\Core\Repository\Criteria;

use App\Core\Repository\Criteria\Interface\CriteriaInterface;
use Illuminate\Database\Eloquent\Builder;

class OrderByCriteria implements CriteriaInterface
{
    private string $column;
    private string $direction;

    public function __construct(string $column, string $direction = 'asc')
    {
        $this->column = $column;
        $this->direction = $direction;
    }

    public function apply(Builder $query): Builder
    {
        return $query->orderBy($this->column, $this->direction);
    }
}

// File: app/Core/Repository/Concerns/HasCache.php
<?php

namespace App\Core\Repository\Concerns;

use Illuminate\Support\Facades\Cache;
use Closure;

trait HasCache
{
    protected function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? config('cache.ttl', 3600);
        
        return Cache::tags($this->getCacheTags())
            ->remember($key, $ttl, $callback);
    }
    
    protected function forget(string $key): void
    {
        Cache::tags($this->getCacheTags())->forget($key);
    }
    
    protected function flush(): void
    {
        Cache::tags($this->getCacheTags())->flush();
    }
    
    protected function getCacheTags(): array
    {
        return [class_basename($this->model())];
    }
}

// File: app/Core/Repository/Concerns/HasCriteria.php
<?php

namespace App\Core\Repository\Concerns;

use App\Core\Repository\Criteria\Interface\CriteriaInterface;

trait HasCriteria
{
    public function pushCriteria(CriteriaInterface $criteria): self
    {
        $this->criteria[] = $criteria;
        return $this;
    }
    
    public function popCriteria(): self
    {
        array_pop($this->criteria);
        return $this;
    }
    
    public function resetCriteria(): self
    {
        $this->criteria = [];
        return $this;
    }
}
