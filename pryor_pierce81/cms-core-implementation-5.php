<?php

namespace App\Core\CMS;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\{SecurityManager, ValidationService};
use App\Core\Interfaces\{
    ContentManagerInterface,
    SecurityManagerInterface,
    CacheManagerInterface
};

class ContentManager implements ContentManagerInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ValidationService $validator;
    private ContentRepository $repository;
    private ContentAuditor $auditor;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ValidationService $validator,
        ContentRepository $repository,
        ContentAuditor $auditor
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->auditor = $auditor;
    }

    public function create(array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new CreateContentOperation($data, $this->repository)
        );
    }

    public function update(int $id, array $data): Content
    {
        return $this->security->executeCriticalOperation(
            new UpdateContentOperation($id, $data, $this->repository)
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteContentOperation($id, $this->repository)
        );
    }

    public function publish(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new PublishContentOperation($id, $this->repository)
        );
    }

    public function version(int $id): ContentVersion
    {
        return $this->security->executeCriticalOperation(
            new VersionContentOperation($id, $this->repository)
        );
    }
}

abstract class ContentOperation implements CriticalOperation
{
    protected ContentRepository $repository;

    public function __construct(ContentRepository $repository)
    {
        $this->repository = $repository;
    }

    abstract public function execute(): mixed;
    abstract public function validate(): bool;
    abstract public function getSecurityLevel(): string;
}

class CreateContentOperation extends ContentOperation
{
    private array $data;

    public function __construct(array $data, ContentRepository $repository)
    {
        parent::__construct($repository);
        $this->data = $data;
    }

    public function execute(): Content
    {
        return DB::transaction(function() {
            $content = $this->repository->create($this->data);
            $this->repository->createVersion($content);
            return $content;
        });
    }

    public function validate(): bool
    {
        return !empty($this->data['title']) && 
               !empty($this->data['content']) &&
               strlen($this->data['title']) <= 255;
    }

    public function getSecurityLevel(): string
    {
        return 'high';
    }
}

class ContentRepository
{
    private ValidationService $validator;

    public function create(array $data): Content
    {
        $this->validator->validate($data, [
            'title' => 'required|max:255',
            'content' => 'required',
            'status' => 'required|in:draft,published'
        ]);

        return Content::create($data);
    }

    public function createVersion(Content $content): ContentVersion
    {
        return ContentVersion::create([
            'content_id' => $content->id,
            'data' => json_encode($content->toArray()),
            'created_by' => auth()->id()
        ]);
    }

    public function update(Content $content, array $data): Content
    {
        $this->validator->validate($data, [
            'title' => 'required|max:255',
            'content' => 'required',
            'status' => 'required|in:draft,published'
        ]);

        $content->update($data);
        return $content;
    }

    public function delete(Content $content): bool
    {
        return $content->delete();
    }
}

class ContentAuditor
{
    public function logAccess(Content $content, string $action): void
    {
        ContentAudit::create([
            'content_id' => $content->id,
            'action' => $action,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function logVersion(Content $content, ContentVersion $version): void
    {
        ContentVersionLog::create([
            'content_id' => $content->id,
            'version_id' => $version->id,
            'created_by' => auth()->id(),
            'changes' => json_encode($version->getDiff())
        ]);
    }
}

class Content extends Model
{
    protected $fillable = [
        'title',
        'content',
        'status',
        'published_at',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'meta' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($content) {
            $content->created_by = auth()->id();
        });

        static::updating(function ($content) {
            $content->updated_by = auth()->id();
        });
    }

    public function versions()
    {
        return $this->hasMany(ContentVersion::class);
    }

    public function audit()
    {
        return $this->hasMany(ContentAudit::class);
    }
}

class ContentVersion extends Model
{
    protected $fillable = [
        'content_id',
        'data',
        'created_by'
    ];

    protected $casts = [
        'data' => 'array'
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function getDiff(): array
    {
        $previous = $this->content->versions()
            ->where('id', '<', $this->id)
            ->latest()
            ->first();

        if (!$previous) {
            return $this->data;
        }

        return array_diff_assoc($this->data, $previous->data);
    }
}

class ContentAudit extends Model
{
    protected $fillable = [
        'content_id',
        'action',
        'user_id',
        'ip_address',
        'user_agent',
        'meta'
    ];

    protected $casts = [
        'meta' => 'array'
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
