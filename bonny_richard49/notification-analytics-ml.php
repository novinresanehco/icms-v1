<?php

namespace App\Core\Notification\Analytics\ML;

use Illuminate\Support\Facades\Redis;
use App\Core\Notification\Analytics\Events\ModelUpdated;

class NotificationMLEngine
{
    private array $config;
    private array $models = [];
    
    public function __construct()
    {
        $this->config = config('analytics.ml');
    }

    public function predictEngagement(array $features): array
    {
        $model = $this->loadModel('engagement');
        return $model->predict($this->preprocessFeatures($features));
    }

    public function predictOptimalTime(array $features): array
    {
        $model = $this->loadModel('timing');
        return $model->predict($this->preprocessFeatures($features));
    }

    public function predictDeliverySuccess(array $features): float
    {
        $model = $this->loadModel('delivery');
        return $model->predict($this->preprocessFeatures($features));
    }

    public function trainModel(string $modelType, array $trainingData): void
    {
        $model = $this->createModel($modelType);
        $model->train($trainingData);
        
        $this->saveModel($modelType, $model);
        event(new ModelUpdated($modelType));
    }

    public function updateModel(string $modelType, array $newData): void
    {
        $model = $this->loadModel($modelType);
        $model->update($newData);
        
        $this->saveModel($modelType, $model);
        event(new ModelUpdated($modelType, 'update'));
    }

    public function evaluateModel(string $modelType, array $testData): array
    {
        $model = $this->loadModel($modelType);
        return $model->evaluate($testData);
    }

    private function loadModel(string $modelType)
    {
        if (isset($this->models[$modelType])) {
            return $this->models[$modelType];
        }

        $modelData = Redis::get("ml:models:{$modelType}");
        if ($modelData) {
            $this->models[$modelType] = unserialize($modelData);
            return $this->models[$modelType];
        }

        return $this->createModel($modelType);
    }

    private function createModel(string $modelType)
    {
        $config = $this->config['models'][$modelType];
        $class = $config['class'];
        
        $model = new $class($config['parameters']);
        $this->models[$modelType] = $model;
        
        return $model;
    }

    private function saveModel(string $modelType, $model): void
    {
        Redis::set("ml:models:{$modelType}", serialize($model));
        Redis::set("ml:models:{$modelType}:updated", time());
    }

    private function preprocessFeatures(array $features): array
    {
        $processed = [];
        
        foreach ($features as $key => $value) {
            if (is_string($value)) {
                $processed[$key] = $this->encodeCategory($value);
            } elseif (is_numeric($value)) {
                $processed[$key] = $this->normalizeNumeric($value, $key);
            }
        }

        return $processed;
    }

    private function encodeCategory(string $value): array
    {
        $categories = Redis::get('ml:categories') ?: '{}';
        $categories = json_decode($categories, true);
        
        if (!isset($categories[$value])) {
            $categories[$value] = count($categories);
            Redis::set('ml:categories', json_encode($categories));
        }
        
        return $this->oneHotEncode($categories[$value], count($categories));
    }

    private function normalizeNumeric(float $value, string $feature): float
    {
        $stats = Redis::hgetall("ml:feature_stats:{$feature}");
        
        if (empty($stats)) {
            return $value;
        }
        
        return ($value - $stats['mean']) / ($stats['std'] ?: 1);
    }

    private function oneHotEncode(int $index, int $size): array
    {
        $encoded = array_fill(0, $size, 0);
        $encoded[$index] = 1;
        return $encoded;
    }
}
