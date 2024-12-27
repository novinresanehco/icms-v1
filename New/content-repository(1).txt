<?php

namespace App\Core\Repositories;

use App\Core\Security\{EncryptionService, AuditService};
use App\Models\Content;
use Illuminate\Support\Facades\{Cache, DB};
use App\Exceptions\RepositoryException;

class ContentRepository extends BaseRepository
{
    protected EncryptionService $encryption;
    protected AuditService $auditService;
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(
        EncryptionService $encryption,
        AuditService $auditService
    ) {
        $this->encryption = $encryption;
        $this->auditService = $auditService;
    }

    public function find(int $id): ?Content
    {
        return Cache::remember(
            "content:{$id}", 
            self::CACHE_TTL,
            fn() => Content::find($id)
        );
    }