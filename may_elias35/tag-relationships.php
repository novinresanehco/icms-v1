<?php

namespace App\Core\Tags\Relationships;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class TagRelationshipManager implements TagRelationshipInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private array $config;

    private const MAX_DEPTH = 5;
    private const MAX_RELATIONSHIPS = 100;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function createRelationship(int $sourceId, int $targetId, string $type): RelationshipResponse
    {
        return $this->security->executeSecureOperation(function() use ($sourceId, $targetId, $type) {
            $this->validateRelationship($sourceId, $targetId, $type);
            
            DB::beginTransaction();
            try {
                // Check existing relationship
                if ($this->relationshipExists($sourceId, $targetId)) {
                    throw new RelationshipException('Relationship already exists');
                }
                
                // Create relationship
                $relationship = $this->storeRelationship($sourceId, $targetId, $type);
                
                // Update hierarchy if needed
                if ($type === 'parent') {
                    $this->updateHierarchy($sourceId, $targetId);
                }
                
                // Update relationship counts
                $this->updateRelationshipCounts($sourceId, $targetId);
                
                DB::commit();
                
                // Invalidate caches
                $this->invalidateRelationshipCaches($sourceId, $targetId);
                
                return new RelationshipResponse($relationship);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new RelationshipException('Failed to create relationship: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'relationship_create']);
    }

    private function validateRelationship(int $sourceId, int $targetId, string $type): void
    {
        // Validate type
        if (!in_array($type, $this->config['allowed_relationship_types'])) {
            throw new ValidationException('Invalid relationship type');
        }

        // Prevent self-referencing
        if ($sourceId === $targetId) {
            throw new ValidationException('Cannot create self-referencing relationship');
        }

        // Check depth limit
        if ($type === 'parent' && $this->wouldExceedDepthLimit($sourceId, $targetId)) {
            throw new ValidationException('Would exceed maximum hierarchy depth');
        }

        // Check relationship limit
        if ($this->wouldExceedRelationshipLimit($sourceId)) {
            throw new ValidationException('Would exceed maximum relationships');
        }

        // Check for cycles
        if ($this->wouldCreateCycle($sourceId, $targetId)) {
            throw new ValidationException('Would create circular reference');
        }
    }

    private function storeRelationship(int $sourceId, int $targetId, string $type): TagRelationship
    {
        $relationship = new TagRelationship([
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'type' => $type,
            'created_by' => auth()->id(),
            'metadata' => $this->generateRelationshipMetadata()
        ]);
        
        $relationship->save();
        
        return $relationship;
    }

    private function updateHierarchy(int $sourceId, int $targetId): void
    {
        // Update hierarchy paths
        $paths = $this->calculateHierarchyPaths($sourceId, $targetId);
        
        foreach ($paths as $path) {
            $this->storeHierarchyPath($path);
        }
        
        // Update descendant paths
        $this->updateDescendantPaths($sourceId);
    }

    private function calculateHierarchyPaths(int $sourceId, int $targetId): array
    {
        $paths = [];
        
        // Get parent paths
        $parentPaths = $this->getParentPaths($targetId);
        
        // Calculate new paths
        foreach ($parentPaths as $parentPath) {
            $paths[] = array_merge($parentPath, [$sourceId]);
        }
        
        return $paths;
    }

    private function updateDescendantPaths(int $sourceId): void
    {
        $descendants = $this->getDescendants($sourceId);
        
        foreach ($descendants as $descendant) {
            $paths = $this->calculateHierarchyPaths($descendant, $sourceId);
            foreach ($paths as $path) {
                $this->storeHierarchyPath($path);
            }
        }
    }

    private function wouldExceedDepthLimit(int $sourceId, int $targetId): bool
    {
        return $this->getHierarchyDepth($targetId) >= self::MAX_DEPTH;
    }

    private function wouldExceedRelationshipLimit(int $sourceId): bool
    {
        return $this->getRelationshipCount($sourceId) >= self::MAX_RELATIONSHIPS;
    }

    private function wouldCreateCycle(int $sourceId, int $targetId): bool
    {
        return $this->isDescendant($targetId, $sourceId);
    }

    private function generateRelationshipMetadata(): array
    {
        return [
            'created_at' => now(),
            'created_by' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];
    }

    private function invalidateRelationshipCaches(int $sourceId, int $targetId): void
    {
        $this->cache->invalidate([
            "tag_relationships:{$sourceId}",
            "tag_relationships:{$targetId}",
            "tag_hierarchy:{$sourceId}",
            "tag_hierarchy:{$targetId}",
            'tag_relationships:count'
        ]);
    }
}
