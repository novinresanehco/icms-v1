<?php

namespace App\Core\Api;

class ApiController extends Controller
{
    private ContentService $content;
    private SecurityValidator $security;
    private ApiTransformer $transformer;
    private RateLimiter $limiter;

    public function __construct(
        ContentService $content,
        SecurityValidator $security,
        ApiTransformer $transformer,
        RateLimiter $limiter
    ) {
        $this->content = $content;
        $this->security = $security;
        $this->transformer = $transformer;
        $this->limiter = $limiter;
    }

    public function index(ApiRequest $request): JsonResponse
    {
        $this->limiter->check($request);
        
        $operation = new ApiListOperation($request, $this->content);
        $result = $this->security->validateOperation($operation);
        
        return response()->json(
            $this->transformer->transform($result->getData())
        );
    }

    public function show(int $id): JsonResponse
    {
        $operation = new ApiFetchOperation($id, $this->content);
        $result = $this->security->validateOperation($operation);
        
        return response()->json(
            $this->transformer->transform($result->getData())
        );
    }

    public function store(ApiRequest $request): JsonResponse
    {
        $operation = new ApiStoreOperation($request, $this->content);
        $result = $this->security->validateOperation($operation);
        
        return response()->json(
            $this->transformer->transform($result->getData()),
            201
        );
    }

    public function update(ApiRequest $request, int $id): JsonResponse
    {
        $operation = new ApiUpdateOperation($request, $id, $this->content);
        $result = $this->security->validateOperation($operation);
        
        return response()->json(
            $this->transformer->transform($result->getData())
        );
    }
}

class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->hasValidApiToken();
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'in:draft,published',
            'category_id' => 'exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id'
        ];
    }
}

class ApiListOperation implements Operation
{
    private ApiRequest $request;
    private ContentService $content;

    public function __construct(ApiRequest $request, ContentService $content)
    {
        $this->request = $request;
        $this->content = $content;
    }

    public function getData(): array
    {
        return $this->request->validated();
    }

    public function execute(): OperationResult
    {
        $contents = $this->content->paginate(
            $this->request->get('page', 1),
            $this->request->get('per_page', 15)
        );
        return new OperationResult(['contents' => $contents]);
    }
}

class ApiFetchOperation implements Operation
{
    private int $id;
    private ContentService $content;

    public function __construct(int $id, ContentService $content)
    {
        $this->id = $id;
        $this->content = $content;
    }

    public function getData(): array
    {
        return ['id' => $this->id];
    }

    public function execute(): OperationResult
    {
        $content = $this->content->find($this->id);
        
        if (!$content) {
            throw new ResourceNotFoundException();
        }
        
        return new OperationResult(['content' => $content]);
    }
}

interface ApiTransformer
{
    public function transform(array $data): array;
}

interface RateLimiter
{
    public function check(Request $request): void;
}