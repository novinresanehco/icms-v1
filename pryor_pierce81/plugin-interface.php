```php
namespace App\Core\Plugin\Contracts;

interface PluginInterface
{
    /**
     * Get plugin name
     */
    public function getName(): string;

    /**
     * Get plugin version
     */
    public function getVersion(): string;

    /**
     * Get plugin description
     */
    public function getDescription(): string;

    /**
     * Get plugin dependencies
     */
    public function getDependencies(): array;

    /**
     * Initialize plugin
     */
    public function initialize(): void;

    /**
     * Enable plugin
     */
    public function enable(): void;

    /**
     * Disable plugin
     */
    public function disable(): void;

    /**
     * Check if plugin is enabled
     */
    public function isEnabled(): bool;

    /**
     * Get plugin configuration
     */
    public function getConfig(): array;

    /**
     * Register plugin hooks
     */
    public function registerHooks(): void;

    /**
     * Register plugin services
     */
    public function registerServices(): void;

    /**
     * Handle plugin updates
     */
    public function update(string $oldVersion, string $newVersion): void;
}

interface PluginHookInterface
{
    /**
     * Execute hook
     */
    public function execute(array $params = []): mixed;

    /**
     * Get hook priority
     */
    public function getPriority(): int;
}

interface PluginEventInterface
{
    /**
     * Get event name
     */
    public function getName(): string;

    /**
     * Get event data
     */
    public function getData(): array;
}
```
