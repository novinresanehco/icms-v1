<?php

namespace App\Core\Transform;

class DataTransformer
{
    private array $transformers = [];
    private array $pipeline = [];

    public function transform($data, array $rules)
    {
        $result = $data;
        
        foreach ($rules as $field => $transformations) {
            $result = $this->applyTransformations($result, $field, $transformations);
        }
        
        return $result;
    }

    public function registerTransformer(string $name, callable $transformer): void
    {
        $this->transformers[$name] = $transformer;
    }

    public function pipeline(): TransformPipeline
    {
        return new TransformPipeline($this->transformers);
    }

    private function applyTransformations($data, string $field, array $transformations)
    {
        $value = $data[$field] ?? null;

        foreach ($transformations as $transformation) {
            if (!isset($this->transformers[$transformation])) {
                throw new TransformException("Unknown transformer: {$transformation}");
            }
            
            $value = $this->transformers[$transformation]($value);
        }

        $data[$field] = $value;
        return $data;
    }
}

class TransformPipeline
{
    private array $steps = [];
    private array $transformers;

    public function __construct(array $transformers)
    {
        $this->transformers = $transformers;
    }

    public function addStep(string $transformer, array $options = []): self
    {
        $this->steps[] = [
            'transformer' => $transformer,
            'options' => $options
        ];
        return $this;
    }

    public function process($data)
    {
        $result = $data;

        foreach ($this->steps as $step) {
            if (!isset($this->transformers[$step['transformer']])) {
                throw new TransformException("Unknown transformer: {$step['transformer']}");
            }

            $transformer = $this->transformers[$step['transformer']];
            $result = $transformer($result, $step['options']);
        }

        return $result;
    }
}

class StringTransformer
{
    public function trim($value): string
    {
        return trim($value);
    }

    public function uppercase($value): string
    {
        return strtoupper($value);
    }

    public function lowercase($value): string
    {
        return strtolower($value);
    }

    public function capitalize($value): string
    {
        return ucfirst(strtolower($value));
    }

    public function slug($value): string
    {
        return str_replace(' ', '-', strtolower(trim($value)));
    }
}

class NumberTransformer
{
    public function round($value, array $options = []): float
    {
        $precision = $options['precision'] ?? 0;
        return round($value, $precision);
    }

    public function ceil($value): float
    {
        return ceil($value);
    }

    public function floor($value): float
    {
        return floor($value);
    }

    public function format($value, array $options = []): string
    {
        $decimals = $options['decimals'] ?? 2;
        $decPoint = $options['dec_point'] ?? '.';
        $thousandsSep = $options['thousands_sep'] ?? ',';
        
        return number_format($value, $decimals, $decPoint, $thousandsSep);
    }
}

class DateTransformer
{
    public function format($value, array $options = []): string
    {
        $format = $options['format'] ?? 'Y-m-d H:i:s';
        $date = is_string($value) ? new \DateTime($value) : $value;
        return $date->format($format);
    }

    public function addDays($value, array $options = []): \DateTime
    {
        $days = $options['days'] ?? 0;
        $date = is_string($value) ? new \DateTime($value) : $value;
        return $date->modify("+{$days} days");
    }

    public function startOfDay($value): \DateTime
    {
        $date = is_string($value) ? new \DateTime($value) : $value;
        return $date->setTime(0, 0, 0);
    }

    public function endOfDay($value): \DateTime
    {
        $date = is_string($value) ? new \DateTime($value) : $value;
        return $date->setTime(23, 59, 59);
    }
}

class ArrayTransformer
{
    public function map($value, array $options = []): array
    {
        $callback = $options['callback'];
        return array_map($callback, $value);
    }

    public function filter($value, array $options = []): array
    {
        $callback = $options['callback'];
        return array_filter($value, $callback);
    }

    public function sort($value, array $options = []): array
    {
        $key = $options['key'] ?? null;
        $direction = $options['direction'] ?? 'asc';
        
        if ($key) {
            usort($value, function($a, $b) use ($key, $direction) {
                return $direction === 'asc' ? 
                    $a[$key] <=> $b[$key] : 
                    $b[$key] <=> $a[$key];
            });
        } else {
            $direction === 'asc' ? sort($value) : rsort($value);
        }
        
        return $value;
    }

    public function groupBy($value, array $options = []): array
    {
        $key = $options['key'];
        $result = [];
        
        foreach ($value as $item) {
            $groupKey = $item[$key];
            $result[$groupKey][] = $item;
        }
        
        return $result;
    }
}

class JsonTransformer
{
    public function encode($value, array $options = []): string
    {
        $flags = $options['flags'] ?? 0;
        return json_encode($value, $flags);
    }

    public function decode($value, array $options = []): array
    {
        $assoc = $options['assoc'] ?? true;
        return json_decode($value, $assoc);
    }
}

class TransformException extends \Exception {}
