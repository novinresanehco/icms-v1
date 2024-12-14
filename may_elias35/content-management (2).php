namespace App\Core\Content;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\{CacheManager, ValidationService};
use App\Core\Exceptions\ContentException;
use Illuminate\Support\Facades\DB;

class ContentManager
{
    private CoreSecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;

    public function __construct(
        CoreSecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function createContent(array $data, array $context): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->doCreateContent($data),
            $context
        );
    }

    private function doCreateContent(array $data): Content
    {
        $validated = $this->validator->validate($data, [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id'
        ]);

        $content = DB::transaction(function() use ($validated) {
            $content = Content::create($validated);
            
            if (isset($validated['meta'])) {
                $content->meta()->create($validated['meta']);
            }
            
            $this->cache->tags(['content'])->put(
                "content.{$content->id}",
                $content,
                3600
            );
            
            return $content;
        });

        $this->processContentVersioning($content);
        
        return $content;
    }

    public function updateContent(int $id, array $data, array $context): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->doUpdateContent($id, $data),
            $context
        );
    }

    private function doUpdateContent(int $id, array $data): Content
    {
        $content = Content::findOrFail($id);
        
        $validated = $this->validator->validate($data, [
            'title' => 'string|max:255',
            'content' => 'string',
            'status' => 'in:draft,published'
        ]);

        DB::transaction(function() use ($content, $validated) {
            $content->update($validated);
            
            if (isset($validated['meta'])) {
                $content->meta()->update($validated['meta']);
            }
            
            $this->cache->tags(['content'])->forget("content.{$content->id}");
            $this->cache->tags(['content'])->put(
                "content.{$content->id}",
                $content->fresh(),
                3600
            );
        });

        $this->processContentVersioning($content);
        
        return $content->fresh();
    }

    private function processContentVersioning(Content $content): void
    {
        ContentVersion::create([
            'content_id' => $content->id,
            'title' => $content->title,
            'content' => $content->content,
            'meta' => $content->meta,
            'version' => $this->generateVersionNumber($content)
        ]);
    }

    private function generateVersionNumber(Content $content): string
    {
        $latestVersion = ContentVersion::where('content_id', $content->id)
            ->latest()
            ->first();
            
        if (!$latestVersion) {
            return '1.0.0';
        }

        $parts = explode('.', $latestVersion->version);
        $parts[2] = ((int)$parts[2]) + 1;
        
        return implode('.', $parts);
    }

    public function publishContent(int $id, array $context): Content
    {
        return $this->security->executeSecureOperation(
            fn() => $this->doPublishContent($id),
            $context
        );
    }

    private function doPublishContent(int $id): Content
    {
        $content = Content::findOrFail($id);
        
        if ($content->status === 'published') {
            throw new ContentException('Content is already published');
        }

        DB::transaction(function() use ($content) {
            $content->update(['status' => 'published', 'published_at' => now()]);
            
            $this->cache->tags(['content'])->forget("content.{$content->id}");
            $this->cache->tags(['content'])->put(
                "content.{$content->id}",
                $content->fresh(),
                3600
            );
        });

        return $content->fresh();
    }
}
