<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, Log};
use App\Exceptions\{TemplateException, RenderException};

class TemplateEngine
{
    protected TemplateCompiler $compiler;
    protected TemplateCache $cache;
    protected TemplateLoader $loader;
    protected array $globals = [];

    public function render(string $template, array $data = []): string 
    {
        try {
            $cacheKey = $this->getCacheKey($template, $data);
            
            return Cache::remember($cacheKey, 3600, function() use ($template, $data) {
                $source = $this->loader->load($template);
                $compiled = $this->compiler->compile($source);
                return $this->renderTemplate($compiled, array_merge($this->globals, $data));
            });
        } catch (\Exception $e) {
            Log::error('Template render failed', [
                'template' => $template,
                'error' => $e->getMessage()
            ]);
            throw new RenderException('Failed to render template');
        }
    }

    protected function renderTemplate(CompiledTemplate $template, array $data): string 
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $template->getPath();
            return ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}

class TemplateLoader 
{
    protected array $paths = [];
    protected array $cache = [];
    
    public function load(string $name): string 
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        foreach ($this->paths as $path) {
            $file = $path . '/' . $name;
            if (file_exists($file)) {
                return $this->cache[$name] = file_get_contents($file);
            }
        }
        
        throw new TemplateException("Template $name not found");
    }
}

class TemplateCompiler 
{
    protected array $extensions = [];
    
    public function compile(string $source): CompiledTemplate 
    {
        $php = $this->compileString($source);
        $path = $this->createTemporaryFile($php);
        
        return new CompiledTemplate($path);
    }

    protected function compileString(string $source): string 
    {
        $result = $source;

        // Basic compilation
        $result = $this->compileComments($result);
        $result = $this->compileEchos($result);
        $result = $this->compilePhp($result);
        
        // Extensions
        foreach ($this->extensions as $extension) {
            $result = $extension->compile($result);
        }

        return $result;
    }

    protected function createTemporaryFile(string $content): string 
    {
        $path = storage_path('framework/views/' . sha1(uniqid()));
        file_put_contents($path, $content);
        return $path;
    }
}

namespace App\Core\Infrastructure;

class CacheManager 
{
    protected array $stores = [];
    protected string $default = 'file';
    
    public function store(string $name = null): CacheStore 
    {
        $name = $name ?: $this->default;
        
        if (!isset($this->stores[$name])) {
            $this->stores[$name] = $this->createStore($name);
        }
        
        return $this->stores[$name];
    }

    public function tags(array $names): TaggedCache 
    {
        return new TaggedCache($this->store(), $names);
    }
}

class FileSystem 
{
    protected string $root;
    
    public function exists(string $path): bool 
    {
        return file_exists($this->root . '/' . $path);
    }
    
    public function get(string $path): string 
    {
        $contents = file_get_contents($this->root . '/' . $path);
        
        if ($contents === false) {
            throw new \RuntimeException("File not readable: $path");
        }
        
        return $contents;
    }
    
    public function put(string $path, string $contents): bool 
    {
        return file_put_contents($this->root . '/' . $path, $contents) !== false;
    }
}

class Logger 
{
    protected array $handlers = [];
    protected array $processors = [];
    
    public function log(string $level, string $message, array $context = []): void 
    {
        $record = $this->processRecord([
            'message' => $message,
            'context' => $context,
            'level' => $level,
            'datetime' => new \DateTime()
        ]);
        
        foreach ($this->handlers as $handler) {
            $handler->handle($record);
        }
    }
    
    protected function processRecord(array $record): array 
    {
        foreach ($this->processors as $processor) {
            $record = $processor($record);
        }
        return $record;
    }
}

class QueueManager 
{
    protected array $connections = [];
    protected array $connectors = [];
    
    public function connection(string $name = null): Queue 
    {
        $name = $name ?: $this->default;
        
        if (!isset($this->connections[$name])) {
            $this->connections[$name] = $this->resolve($name);
        }
        
        return $this->connections[$name];
    }
    
    protected function resolve(string $name): Queue 
    {
        $config = $this->getConfig($name);
        return $this->getConnector($config['driver'])->connect($config);
    }
}

class TaggedCache 
{
    protected CacheStore $store;
    protected array $tags;
    
    public function remember(string $key, int $ttl, \Closure $callback) 
    {
        $value = $this->get($key);
        
        if (!is_null($value)) {
            return $value;
        }
        
        $value = $callback();
        $this->put($key, $value, $ttl);
        
        return $value;
    }
}

class CompiledTemplate 
{
    protected string $path;
    
    public function __construct(string $path) 
    {
        $this->path = $path;
    }
    
    public function getPath(): string 
    {
        return $this->path;
    }
}
