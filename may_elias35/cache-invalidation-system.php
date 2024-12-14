// File: app/Core/Cache/Invalidation/InvalidationManager.php
<?php

namespace App\Core\Cache\Invalidation;

class InvalidationManager
{
    protected DependencyGraph $graph;
    protected InvalidationQueue $queue;
    protected EventDispatcher $events;
    protected InvalidationConfig $config;

    public function invalidate(string $key, array $tags = []): void
    {
        $dependencies = $this->graph->getDependencies($key, $tags);
        
        DB::transaction(function() use ($dependencies) {
            foreach ($dependencies as $dependency) {
                $this->queue->push(new InvalidationJob($dependency));
            }
        });

        $this->events->dispatch(new CacheInvalidated($key, $dependencies));
    }

    public function addDependency(string $key, string $dependency): void
    {
        $this->graph->addDependency($key, $dependency);
    }

    public function removeDependency(string $key, string $dependency): void
    {
        $this->graph->removeDependency($key, $dependency);
    }
}

// File: app/Core/Cache/Invalidation/DependencyGraph.php
<?php

namespace App\Core\Cache\Invalidation;

class DependencyGraph
{
    protected GraphStore $store;
    protected GraphTraverser $traverser;
    protected GraphValidator $validator;

    public function addDependency(string $key, string $dependency): void
    {
        if ($this->validator->wouldCreateCycle($key, $dependency)) {
            throw new CircularDependencyException();
        }

        $this->store->addEdge($key, $dependency);
    }

    public function getDependencies(string $key, array $tags = []): array
    {
        $dependencies = $this->traverser->traverse($key);
        
        if (!empty($tags)) {
            $dependencies = array_merge(
                $dependencies,
                $this->getTagDependencies($tags)
            );
        }

        return array_unique($dependencies);
    }

    protected function getTagDependencies(array $tags): array
    {
        $dependencies = [];
        
        foreach ($tags as $tag) {
            $dependencies = array_merge(
                $dependencies,
                $this->store->getTaggedKeys($tag)
            );
        }

        return $dependencies;
    }
}
