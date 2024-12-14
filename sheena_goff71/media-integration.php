<?php
namespace App\Core\Media;

class MediaIntegrationSystem {
    private SecurityValidator $validator;
    private MediaProcessor $processor;
    private CacheManager $cache;

    public function __construct(
        SecurityValidator $validator,
        MediaProcessor $processor,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->processor = $processor;
        $this->cache = $cache;
    }

    public function processMedia(MediaRequest $request): MediaResult {
        return $this->executeSecure(function() use ($request) {
            $validated = $this->validator->validateRequest($request);
            $processed = $this->processor->process($validated);
            
            return $this->cache->remember(
                $this->getCacheKey($request),
                fn() => $this->createResult($processed)
            );
        });
    }

    private function executeSecure(callable $operation): MediaResult {
        try {
            $this->validator->validateContext();
            return $operation();
        } catch (SecurityException $e) {
            throw new MediaException('Media processing failed: ' . $e->getMessage());
        }
    }

    private function getCacheKey(MediaRequest $request): string {
        return sprintf('media.%s', $request->getId());
    }

    private function createResult(ProcessedMedia $media): MediaResult {
        return new MediaResult($media->getData());
    }
}

class SecurityValidator {
    public function validateContext(): void {
        if (!$this->checkSecurityContext()) {
            throw new SecurityException('Invalid security context');
        }
    }

    public function validateRequest(MediaRequest $request): ValidatedRequest {
        return new ValidatedRequest($request->getData());
    }

    private function checkSecurityContext(): bool {
        return true;
    }
}

class MediaProcessor {
    public function process(ValidatedRequest $request): ProcessedMedia {
        return new ProcessedMedia([]);
    }
}

class CacheManager {
    public function remember(string $key, callable $callback) {
        return $callback();
    }
}

class MediaRequest {
    private string $id;
    private array $data;

    public function getId(): string {
        return $this->id;
    }

    public function getData(): array {
        return $this->data;
    }
}

class ValidatedRequest {
    private array $data;

    public function __construct(array $data) {
        $this->data = $data;
    }
}

class ProcessedMedia {
    private array $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function getData(): array {
        return $this->data;
    }
}

class MediaResult {
    private array $data;

    public function __construct(array $data) {
        $this->data = $data;
    }
}

class SecurityException extends \Exception {}
class MediaException extends \Exception {}
