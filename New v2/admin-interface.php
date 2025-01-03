<?php

namespace App\Core\Admin;

class AdminController extends Controller
{
    private ContentService $content;
    private SecurityValidator $security;
    private AuditLogger $logger;
    private ViewRenderer $renderer;

    public function __construct(
        ContentService $content,
        SecurityValidator $security,
        AuditLogger $logger,
        ViewRenderer $renderer
    ) {
        $this->content = $content;
        $this->security = $security;
        $this->logger = $logger;
        $this->renderer = $renderer;
    }

    public function index(Request $request): Response
    {
        $operation = new ListOperation($request, $this->content);
        $result = $this->security->validateOperation($operation);
        return $this->renderer->render('admin.index', $result->getData());
    }

    public function store(AdminRequest $request): Response
    {
        $operation = new AdminStoreOperation($request, $this->content);
        $result = $this->security->validateOperation($operation);
        $this->logger->logAdmin('content.created', $result->getData());
        return response()->json($result->getData(), 201);
    }

    public function update(AdminRequest $request, int $id): Response
    {
        $operation = new AdminUpdateOperation($request, $id, $this->content);
        $result = $this->security->validateOperation($operation);
        $this->logger->logAdmin('content.updated', $result->getData());
        return response()->json($result->getData());
    }

    public function delete(int $id): Response
    {
        $operation = new AdminDeleteOperation($id, $this->content);
        $result = $this->security->validateOperation($operation);
        $this->logger->logAdmin('content.deleted', ['id' => $id]);
        return response()->json(['success' => true]);
    }
}

class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id'
        ];
    }
}

class ListOperation implements Operation 
{
    private Request $request;
    private ContentService $content;

    public function __construct(Request $request, ContentService $content)  
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
        $contents = $this->content->paginate($this->request->get('page', 1));
        return new OperationResult(['contents' => $contents]);
    }
}

class AdminStoreOperation implements Operation
{
    private AdminRequest $request;
    private ContentService $content;

    public function __construct(AdminRequest $request, ContentService $content)
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
        $content = $this->content->store($this->getData());
        return new OperationResult(['content' => $content]);
    }
}

class AdminUpdateOperation implements Operation
{
    private AdminRequest $request;
    private int $id;
    private ContentService $content;

    public function __construct(AdminRequest $request, int $id, ContentService $content)
    {
        $this->request = $request;
        $this->id = $id;
        $this->content = $content;
    }

    public function getData(): array
    {
        return array_merge(
            $this->request->validated(),
            ['id' => $this->id]
        );
    }

    public function execute(): OperationResult
    {
        $content = $this->content->update($this->id, $this->request->validated());
        return new OperationResult(['content' => $content]);
    }
}

class AdminDeleteOperation implements Operation
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
        $this->content->delete($this->id);
        return new OperationResult(['success' => true]);
    }
}