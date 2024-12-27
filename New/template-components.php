<?php

namespace App\Core\Template\Components;

use App\Core\Template\Exceptions\ComponentException;

interface ComponentInterface
{
    public function render(array $data = []): string;
    public function validate(array $data = []): bool;
    public function getRequiredData(): array;
}

abstract class BaseComponent implements ComponentInterface
{
    protected array $requiredData = [];
    protected array $defaultData = [];
    protected string $template;

    public function validate(array $data = []): bool
    {
        foreach ($this->requiredData as $key) {
            if (!isset($data[$key])) {
                throw new ComponentException("Missing required data: {$key}");
            }
        }
        return true;
    }

    public function getRequiredData(): array
    {
        return $this->requiredData;
    }

    protected function mergeData(array $data): array
    {
        return array_merge($this->defaultData, $data);
    }
}

class ComponentRegistry
{
    private static array $components = [];

    public static function register(string $name, string $class): void
    {
        if (!class_exists($class)) {
            throw new ComponentException("Component class not found: {$class}");
        }

        if (!is_subclass_of($class, ComponentInterface::class)) {
            throw new ComponentException(
                "Component must implement ComponentInterface: {$class}"
            );
        }

        self::$components[$name] = $class;
    }

    public static function resolve(string $name): ComponentInterface
    {
        if (!isset(self::$components[$name])) {
            throw new ComponentException("Component not found: {$name}");
        }

        $class = self::$components[$name];
        return new $class();
    }
}

class ComponentLoader
{
    private array $loadedComponents = [];
    private string $componentsPath;

    public function __construct(string $componentsPath)
    {
        $this->componentsPath = $componentsPath;
    }

    public function load(string $name): ComponentInterface
    {
        if (isset($this->loadedComponents[$name])) {
            return $this->loadedComponents[$name];
        }

        $component = $this->loadFromRegistry($name);
        $this->loadedComponents[$name] = $component;

        return $component;
    }

    private function loadFromRegistry(string $name): ComponentInterface
    {
        try {
            return ComponentRegistry::resolve($name);
        } catch (ComponentException $e) {
            return $this->loadFromFile($name);
        }
    }

    private function loadFromFile(string $name): ComponentInterface
    {
        $file = $this->componentsPath . '/' . $name . '.php';

        if (!file_exists($file)) {
            throw new ComponentException("Component file not found: {$file}");
        }

        require_once $file;
        return ComponentRegistry::resolve($name);
    }
}

class ComponentCompiler implements Compiler
{
    private ComponentLoader $loader;
    
    public function __construct(ComponentLoader $loader)
    {
        $this->loader = $loader;
    }

    public function compile(string $content): string
    {
        return preg_replace_callback(
            '/@component\s*\(\s*[\'"](.+?)[\'"]\s*(?:,\s*(.+?))?\s*\)/',
            [$this, 'compileComponent'],
            $content
        );
    }

    private function compileComponent(array $matches): string
    {
        $name = $matches[1];
        $data = isset($matches[2]) ? $this->parseData($matches[2]) : '[]';
        
        return "<?php echo \$this->renderComponent('{$name}', {$data}); ?>";
    }

    private function parseData(string $expression): string
    {
        if (empty($expression)) {
            return '[]';
        }

        return trim($expression);
    }
}

trait ComponentRendererTrait
{
    private ComponentLoader $componentLoader;
    
    public function setComponentLoader(ComponentLoader $loader): void
    {
        $this->componentLoader = $loader;
    }
    
    public function renderComponent(string $name, array $data = []): string
    {
        $component = $this->componentLoader->load($name);
        return $component->render($data);
    }
}