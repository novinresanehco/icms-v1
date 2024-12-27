<?php

namespace App\Http\Controllers;

class ContentController extends Controller 
{
    private ContentManager $content;
    private SecurityManager $security;
    private ValidatorService $validator;

    public function store(StoreContentRequest $request): JsonResponse
    {
        return DB::transaction(function() use ($request) {
            $validated = $this->validator->validate($request->all());
            $content = $this->content->store($validated);
            return new JsonResponse($content, 201);
        });
    }

    public function show(int $id): JsonResponse
    {
        $content = $this->content->retrieve($id);
        $this->security->validateAccess($content);
        return new JsonResponse($content);
    }
}

namespace App\Services;

class ContentManager implements ContentManagerInterface
{
    private Repository $repository;
    private SecurityManager $security;
    private CacheManager $cache;

    public function store(array $data): Content
    {
        $protected = $this->security->encrypt(json_encode($data));
        
        return DB::transaction(function() use ($protected) {
            $content = $this->repository->store(['data' => $protected]);
            $this->cache->invalidate(['content', $content->id]);
            return $content;
        });
    }

    public function retrieve(int $id): Content
    {
        return $this->cache->remember(['content', $id], function() use ($id) {
            $content = $this->repository->find($id);
            $data = $this->security->decrypt($content->data);
            return new Content(json_decode($data, true));
        });
    }
}

namespace App\Services;

class SecurityManager implements SecurityManagerInterface
{
    private EncryptionService $encryption;
    private TokenManager $tokens;
    private LoggerService $logger;

    public function encrypt(string $data): string
    {
        $result = $this->encryption->encrypt($data);
        $this->logger->log('encryption', ['status' => 'success']);
        return $result;
    }

    public function decrypt(string $data): string
    {
        return $this->encryption->decrypt($data);
    }
}

namespace App\Repositories;

class ContentRepository extends BaseRepository
{
    protected function model(): string
    {
        return Content::class;
    }

    public function store(array $data): Content
    {
        $content = $this->model->create($data);
        $this->cache->invalidate(['content', $content->id]);
        return $content;
    }
}

namespace App\Models;

class Content extends Model
{
    protected $fillable = ['data'];
    protected $casts = ['data' => 'encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

namespace Tests\Unit;

class ContentManagerTest extends TestCase
{
    public function test_stores_content_securely(): void
    {
        $data = ['title' => 'Test'];
        $content = $this->content->store($data);
        
        $this->assertInstanceOf(Content::class, $content);
        $this->assertEncrypted($content->data);
    }

    public function test_retrieves_decrypted_content(): void
    {
        $id = 1;
        $content = $this->content->retrieve($id);
        
        $this->assertInstanceOf(Content::class, $content);
        $this->assertIsArray($content->data);
    }
}

namespace Tests\Integration;

class ContentApiTest extends TestCase
{
    public function test_stores_content_via_api(): void
    {
        $response = $this->postJson('/api/content', [
            'title' => 'Test Content'
        ]);
        
        $response->assertStatus(201)
                ->assertJson(['title' => 'Test Content']);
    }

    public function test_retrieves_content_via_api(): void
    {
        $id = 1;
        $response = $this->getJson("/api/content/{$id}");
        
        $response->assertStatus(200)
                ->assertJsonStructure(['title', 'data']);
    }
}
