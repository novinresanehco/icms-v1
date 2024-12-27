Operation(callable $operation)
    {
        return DB::transaction(function() use ($operation) {
            try {
                return $operation();
            } catch (\Exception $e) {
                $this->handleContentError($e);
                throw $e;
            }
        });
    }

    protected function validatePublication(Content $content): void
    {
        if (!$this->validator->validatePublicationRules($content)) {
            throw new ValidationException('Content cannot be published');
        }
    }

    protected function handleMediaAttachments(Content $content, array $mediaIds): void
    {
        $content->media()->sync($mediaIds);
        
        foreach ($mediaIds as $mediaId) {
            $this->media->processContentMedia($mediaId, $content);
        }
    }

    protected function handleContentError(\Exception $e): void
    {
        if ($e instanceof ValidationException) {
            $this->events->dispatch(new ContentValidationFailed($e));
        } elseif ($e instanceof SecurityException) {
            $this->events->dispatch(new ContentSecurityEvent($e));
        }
    }

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'in:draft,published',
            'category_id' => 'exists:categories,id',
            'media' => 'array',
            'media.*' => 'exists:media,id'
        ];
    }
}

class ApiContentController extends Controller
{
    private ContentManager $content;
    private SecurityService $security;
    private ApiTransformer $transformer;

    public function __construct(
        ContentManager $content,
        SecurityService $security,
        ApiTransformer $transformer
    ) {
        $this->content = $content;
        $this->security = $security;
        $this->transformer = $transformer;
    }

    public function index(Request $request): JsonResponse
    {
        $this->security->validateRequest($request);

        $contents = $this->content->paginate(
            $request->get('page', 1),
            $request->get('per_page', 15)
        );

        return response()->json(
            $this->transformer->transformCollection($contents)
        );
    }

    public function store(ContentRequest $request): JsonResponse
    {
        $this->security->validateRequest($request);

        $content = $this->content->create(
            $request->validated(),
            $request->user()
        );

        return response()->json(
            $this->transformer->transform($content),
            201
        );
    }

    public function update(ContentRequest $request, int $id): JsonResponse
    {
        $this->security->validateRequest($request);

        $content = $this->content->update(
            $id,
            $request->validated(),
            $request->user()
        );

        return response()->json(
            $this->transformer->transform($content)
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->security->validateRequest($request);

        $this->content->delete($id, $request->user());

        return response()->json(null, 204);
    }
}

class ContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', Content::class);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'in:draft,published',
            'category_id' => 'exists:categories,id',
            'media' => 'array',
            'media.*' => 'exists:media,id',
            'meta' => 'array',
            'meta.description' => 'string|max:255',
            'meta.keywords' => 'array',
            'meta.keywords.*' => 'string|max:50'
        ];
    }
}