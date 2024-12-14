<?php

namespace App\Core\Contracts;

interface SecurityManagerInterface
{
    public function validateAccess(User $user, string $action): bool;
    public function validateOperation(SecurityContext $context): ValidationResult;
    public function sanitizeOutput(string $content): string;
}

interface AuthenticationServiceInterface
{
    public function authenticate(array $credentials): AuthResult;
    public function validateToken(string $token): bool;
    public function refresh(string $token): string;
}

interface ContentManagerInterface
{
    public function createContent(array $data, array $media = []): Content;
    public function updateContent(int $id, array $data): Content;
    public function deleteContent(int $id): bool;
}

interface TemplateManagerInterface
{
    public function render(string $template, array $data = []): string;
    public function compile(string $template): string;
    public function cache(string $template): void;
}

interface CacheManagerInterface
{
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;
    public function invalidate(string $key): void;
    public function flush(): void;
}

interface LogManagerInterface
{
    public function emergency(string $message, array $context = []): void;
    public function alert(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
}

interface RepositoryInterface
{
    public function find(int $id): ?Model;
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function delete(int $id): bool;
}

interface ValidationServiceInterface
{
    public function validate(array $data, array $rules = []): array;
    public function validateContext(array $context): bool;
    public function verifySystemState(): bool;
}

interface MonitoringServiceInterface
{
    public function track(string $operation, callable $callback): mixed;
    public function startOperation(string $operation): string;
    public function stopOperation(string $monitoringId): void;
    public function captureSystemState(): array;
}

interface SecurityContextInterface
{
    public function getUser(): User;
    public function getAction(): string;
    public function getResource(): string;
    public function getData(): array;
}

interface SystemComponentInterface
{
    public function boot(): void;
    public function shutdown(): void;
    public function getStatus(): string;
    public function isHealthy(): bool;
}

interface AuditableInterface
{
    public function getAuditData(): array;
    public function getAuditType(): string;
    public function getAuditUser(): ?User;
}

interface SecureResourceInterface
{
    public function getOwner(): User;
    public function getPermissions(): array;
    public function checkAccess(User $user, string $action): bool;
}

interface CacheableInterface
{
    public function getCacheKey(): string;
    public function getCacheTags(): array;
    public function getCacheDuration(): int;
}

interface RenderableInterface
{
    public function render(array $data = []): string;
    public function getTemplate(): string;
    public function getViewData(): array;
}

interface ValidatableInterface
{
    public function getRules(): array;
    public function getMessages(): array;
    public function validate(): bool;
}

interface AuthResultInterface
{
    public function getUser(): User;
    public function getToken(): string;
    public function getExpiration(): int;
}

interface ValidationResultInterface
{
    public function isValid(): bool;
    public function getErrors(): array;
    public function addError(string $field, string $message): void;
}

interface TokenServiceInterface
{
    public function generate(User $user): string;
    public function verify(string $token): bool;
    public function decode(string $token): array;
}

interface MediaServiceInterface
{
    public function store(UploadedFile $file): Media;
    public function delete(int $id): bool;
    public function optimize(Media $media): void;
}

interface ThemeServiceInterface
{
    public function getActive(): Theme;
    public function setActive(int $id): Theme;
    public function compile(string $template): string;
}

interface ErrorHandlerInterface
{
    public function handle(\Exception $e): void;
    public function report(\Exception $e): void;
    public function shouldReport(\Exception $e): bool;
}
