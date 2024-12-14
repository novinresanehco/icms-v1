<?php

namespace App\Core\Protection;

interface ProtectionInterface
{
    /**
     * Protect operation with comprehensive shield layers
     *
     * @param Operation $operation Operation to protect
     * @return ProtectionResult The protection result
     * @throws ProtectionException If protection fails
     */
    public function protectOperation(Operation $operation): ProtectionResult;
}

interface Operation
{
    /**
     * Get operation data for protection
     *
     * @return array Operation data
     */
    public function getData(): array;

    /**
     * Get operation output
     *
     * @return array Operation output
     */
    public function getOutput(): array;

    /**
     * Get protection requirements
     *
     * @return array Protection requirements
     */
    public function getProtectionRequirements(): array;

    /**
     * Convert operation to array
     * 
     * @return array Operation details
     */
    public function toArray(): array;
}

class Shield
{
    private string $type;
    private array $layers = [];

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    public function addLayer(string $name, callable $protection): void
    {
        $this->layers[$name] = $protection;
    }

    public function apply(): void
    {
        foreach ($this->layers as $layer) {
            $layer();
        }
    }

    public function verifyIntegrity(): bool
    {
        return true;
    }

    public function getLayers(): array
    {
        return array_keys($this->layers);
    }
}

class ProtectionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $protectionId,
        public readonly array $shields
    ) {}
}

class ProtectionException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
