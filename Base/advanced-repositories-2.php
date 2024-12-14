<?php

namespace App\Core\Repositories;

interface ValidatableRepositoryInterface
{
    public function validate(array $data, array $rules = []): bool;
    public function getErrors(): array;
}

abstract class ValidatableRepository extends CacheableRepository implements ValidatableRepositoryInterface
{
    protected array $errors = [];
    protected array $defaultRules = [];

    public function validate(array $data, array $rules = []): bool
    {
        $validator = Validator::make(
            $data,
            $rules ?: $this->defaultRules
        );

        if ($validator->fails()) {
            $this->errors = $validator->errors()->toArray();
            return false;
        }

        return true;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    protected function validateAndCreate(array $data): mixed
    {
        if (!$this->validate($data)) {
            throw new ValidationException($this->getErrors());
        }

        return parent::create($data);
    }

    protected function validateAndUpdate(int $id, array $data): mixed
    {
        if (!$this->validate($data)) {
            throw new ValidationException($this->getErrors());
        }

        return parent::update($id, $data);
    }
}

// Comments Repository
class CommentRepository extends ValidatableRepository
{
    protected array $defaultRules = [
        'content' => 'required|string|min:2|max:1000',
        'author_name' => 'required|string|max:100',
        'author_email' => 'required|email',
        'content_id' => 'required|exists:contents,id',
        'parent_id' => 'nullable|exists:comments,id'
    ];

    protected array $cacheTags = ['comments'];

    protected function model(): string
    {
        return Comment::class;
    }

    public function findForContent(int $contentId, bool $approved = true)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$contentId, $approved]),
            fn() => $this->model
                ->where('content_id', $contentId)
                ->when($approved, fn($query) => $query->where('approved', true))
                ->with('replies')
                ->whereNull('parent_id')
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function approve(int $id): void
    {
        DB::transaction(function() use ($id) {
            $comment = $this->find($id);
            $comment->update(['approved' => true]);
            
            event(new CommentApproved($comment));
        });
        
        $this->clearCache();
    }

    public function spam(int $id): void
    {
        DB::transaction(function() use ($id) {
            $comment = $this->find($id);
            $comment->update(['spam' => true, 'approved' => false]);
            
            event(new CommentMarkedAsSpam($comment));
        });
        
        $this->clearCache();
    }
}

// Revisions Repository
class RevisionRepository extends ValidatableRepository
{
    protected array $defaultRules = [
        'content_id' => 'required|exists:contents,id',
        'user_id' => 'required|exists:users,id',
        'data' => 'required|array',
        'comment' => 'nullable|string|max:500'
    ];

    protected array $cacheTags = ['revisions'];

    protected function model(): string
    {
        return Revision::class;
    }

    public function getVersions(int $contentId)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$contentId]),
            fn() => $this->model
                ->where('content_id', $contentId)
                ->with('user')
                ->orderBy('created_at', 'desc')
                ->get()
        );
    }

    public function createRevision(int $contentId, array $data, string $comment = null): Revision
    {
        return DB::transaction(function() use ($contentId, $data, $comment) {
            $revision = $this->validateAndCreate([
                'content_id' => $contentId,
                'user_id' => auth()->id(),
                'data' => $data,
                'comment' => $comment
            ]);
            
            event(new RevisionCreated($revision));
            
            return $revision;
        });
    }

    public function restore(int $revisionId): Content
    {
        return DB::transaction(function() use ($revisionId) {
            $revision = $this->find($revisionId);
            $content = $revision->content;
            
            // Update content with revision data
            $content->update($revision->data);
            
            event(new RevisionRestored($revision));
            
            return $content->fresh();
        });
    }

    public function compareVersions(int $fromId, int $toId): array
    {
        $from = $this->find($fromId);
        $to = $this->find($toId);
        
        return array_diff_assoc($to->data, $from->data);
    }
}

// Permissions Repository
class PermissionRepository extends ValidatableRepository
{
    protected array $defaultRules = [
        'name' => 'required|string|max:100|unique:permissions',
        'display_name' => 'required|string|max:100',
        'description' => 'nullable|string|max:255',
        'group' => 'nullable|string|max:50'
    ];

    protected array $cacheTags = ['permissions'];

    protected function model(): string
    {
        return Permission::class;
    }

    public function findByName(string $name)
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$name]),
            fn() => $this->model->where('name', $name)->firstOrFail()
        );
    }

    public function getByGroup(): Collection
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__),
            fn() => $this->model
                ->orderBy('group')
                ->orderBy('name')
                ->get()
                ->groupBy('group')
        );
    }

    public function assignToRole(int $permissionId, int $roleId): void
    {
        DB::transaction(function() use ($permissionId, $roleId) {
            $permission = $this->find($permissionId);
            $permission->roles()->attach($roleId);
            
            event(new PermissionAssignedToRole($permission, $roleId));
        });
        
        Cache::tags(['permissions', 'roles'])->flush();
    }

    public function revokeFromRole(int $permissionId, int $roleId): void
    {
        DB::transaction(function() use ($permissionId, $roleId) {
            $permission = $this->find($permissionId);
            $permission->roles()->detach($roleId);
            
            event(new PermissionRevokedFromRole($permission, $roleId));
        });
        
        Cache::tags(['permissions', 'roles'])->flush();
    }
}
