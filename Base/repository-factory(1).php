<?php

namespace App\Core\Repositories\Factories;

use App\Core\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Mockery;

class RepositoryFactory
{
    protected array $repositories = [];

    public function create(string $repositoryClass, array $methods = []): RepositoryInterface
    {
        $mock = Mockery::mock($repositoryClass);

        foreach ($methods as $method => $returnValue) {
            $mock->shouldReceive($method)
                ->andReturn($returnValue);
        }

        $this->repositories[] = $mock;
        return $mock;
    }

    public function createWithModel(string $repositoryClass, Model $model): RepositoryInterface
    {
        $mock = Mockery::mock($repositoryClass);
        
        $mock->shouldReceive('getModel')
            ->andReturn($model);
            
        $mock->shouldReceive('find')
            ->andReturn($model);
            
        $mock->shouldReceive('create')
            ->andReturn($model);
            
        $mock->shouldReceive('update')
            ->andReturn($model);
            
        $this->repositories[] = $mock;
        return $mock;
    }

    public function createWithCollection(string $repositoryClass, Collection $collection): RepositoryInterface
    {
        $mock = Mockery::mock($repositoryClass);
        
        $mock->shouldReceive('all')
            ->andReturn($collection);
            
        $mock->shouldReceive('findWhere')
            ->andReturn($collection);
            
        $this->repositories[] = $mock;
        return $mock;
    }
}
