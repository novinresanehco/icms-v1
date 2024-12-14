<?php

namespace App\Core\CMS\Repositories;

use App\Core\Security\SecurityManagerInterface;
use App\Core\CMS\Models\Content;
use App\Core\Exception\RepositoryException;
use Psr\Log\LoggerInterface;

class ContentRepository implements ContentRepositoryInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function find(int $id): ?Content
    {
        try {
            $this->security->validateContext('content:read');

            $content = Content::find($id);

            if ($content && !$this->security->hasPermission("content:{$id}:read")) {
                throw new RepositoryException('Access denied to content');
            }

            return $content;

        } catch (\Exception $e) {
            $this->handleRepositoryError('find', ['id' => $id], $e);
            throw $e;
        }
    }

    public function findByStatus(string $status, array $options = []): array
    {
        try {
            $this->security->validateContext('content:list');

            $query = Content::where('status', $status);

            if (!empty($options['author_id'])) {
                $query->where('author_id', $options['author_id']);
            }

            if (!empty($options['from_date'])) {
                $query->where('created_at', '>=', $options['from_date']);
            }

            return $query->get()->all();

        } catch (\Exception $e) {
            $this->handleRepositoryError('findByStatus', ['status' => $status], $e);
            throw $e;
        }
    }

    public function save(Content $content): bool
    {
        try {
            $this->security->validateContext('content:save');
            
            if ($content->exists) {
                return $this->update($content);
            }
            
            return $this->create($content);

        } catch (\Exception $e) {
            $this->handleRepositoryError('save', ['content' => $content], $e);
            throw $e;
        }
    }

    private function create(Content $content): bool
    {
        if (!$this->security->hasPermission('content:create')) {
            throw new RepositoryException('Access denied to create content');
        }

        $content->author_id = $this->security->getCurrentUser()->getId();
        return $content->save();
    }

    private function update(Content $content): bool
    {
        if (!$this->security->hasPermission("content:{$content->id}:update")) {
            throw new RepositoryException('Access denied to update content');
        }

        return $content->save();
    }

    private function handleRepositoryError(
        string $operation,
        array $params,
        \Exception $e
    ): void {
        $this->logger->error('Repository operation failed', [
            'operation' => $operation,
            'params' => $params,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'batch_size' => 100,
            'max_results' => 1000
        ];
    }
}
