<?php
namespace App\Core\Infrastructure;

class CacheManager implements CacheInterface 
{
    private $store;
    private $tags = [];

    public function remember(string $key, int $ttl, callable $callback)
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);
        return $value;
    }

    public function tags(array $tags): self 
    {
        $this->tags = $tags;
        return $this;
    }

    public function get(string $key)
    {
        return $this->store->tags($this->tags)->get($key);
    }

    public function put(string $key, $value, int $ttl): bool
    {
        return $this->store->tags($this->tags)->put($key, $value, $ttl);
    }

    public function flush(): bool
    {
        return $this->store->tags($this->tags)->flush();
    }
}

class ErrorHandler implements ErrorHandlerInterface 
{
    private LogManager $logger;
    private AlertManager $alerts;

    public function handle(\Throwable $e, array $context = []): void
    {
        $severity = $this->getSeverity($e);
        
        $this->logger->log($severity, $e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString(),
            'context' => $context
        ]);

        if ($this->isEmergency($severity)) {
            $this->alerts->emergency($e, $context);
        }
    }

    public function getSeverity(\Throwable $e): string
    {
        return match(get_class($e)) {
            SecurityException::class => 'critical',
            ValidationException::class => 'warning',
            default => 'error'
        };
    }

    private function isEmergency(string $severity): bool
    {
        return in_array($severity, ['emergency', 'critical']);
    }
}

class DatabaseManager implements DatabaseInterface
{
    public function transaction(callable $callback)
    {
        DB::beginTransaction();
        
        try {
            $result = $callback();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function query(): QueryBuilder
    {
        return DB::table($this->table)
            ->when(isset($this->with), function($q) {
                return $q->with($this->with);
            });
    }
    
    public function create(array $data)
    {
        return $this->transaction(function() use ($data) {
            return $this->query()->create($data);
        });
    }

    public function update($id, array $data)
    {
        return $this->transaction(function() use ($id, $data) {
            return $this->query()->findOrFail($id)->update($data);
        });
    }
}

class ValidationManager implements ValidationInterface
{
    private array $rules = [];
    private array $messages = [];

    public function validate(array $data, string $ruleset): array
    {
        $rules = $this->rules[$ruleset] ?? throw new ValidationException("Invalid ruleset");
        $validator = Validator::make($data, $rules, $this->messages);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }

        return $validator->validated();
    }

    public function addRules(string $ruleset, array $rules): void
    {
        $this->rules[$ruleset] = $rules;
    }

    public function addMessages(array $messages): void
    {
        $this->messages = array_merge($this->messages, $messages);
    }
}
