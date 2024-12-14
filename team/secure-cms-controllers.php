namespace App\Http\Controllers\CMS;

class ContentController extends BaseController
{
    private SecurityManager $security;
    private ContentManager $content;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function store(ContentRequest $request): JsonResponse
    {
        return $this->security->executeSecureOperation(
            new CreateContentOperation(
                $request->validated(),
                $this->content,
                $this->audit
            ),
            $request->getSecurityContext()
        );
    }

    public function update(ContentRequest $request, int $id): JsonResponse
    {
        return $this->security->executeSecureOperation(
            new UpdateContentOperation(
                $id,
                $request->validated(),
                $this->content,
                $this->audit
            ),
            $request->getSecurityContext()
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->security->executeSecureOperation(
            new DeleteContentOperation(
                $id,
                $this->content,
                $this->audit
            ),
            $request->getSecurityContext()
        );
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        return $this->security->executeSecureOperation(
            new PublishContentOperation(
                $id,
                $this->content,
                $this->audit
            ),
            $request->getSecurityContext()
        );
    }
}

class CategoryController extends BaseController
{
    private SecurityManager $security;
    private CategoryManager $categories;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function store(CategoryRequest $request): JsonResponse
    {
        return $this->security->executeSecureOperation(
            new CreateCategoryOperation(
                $request->validated(),
                $this->categories,
                $this->audit
            ),
            $request->getSecurityContext()
        );
    }

    public function update(CategoryRequest $request, int $id): JsonResponse
    {
        return $this->security->executeSecureOperation(
            new UpdateCategoryOperation(
                $id,
                $request->validated(),
                $this->categories,
                $this->audit
            ),
            $request->getSecurityContext()
        );
    }
}

class MediaController extends BaseController
{
    private SecurityManager $security;
    private MediaManager $media;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function upload(MediaRequest $request): JsonResponse
    {
        return $this->security->executeSecureOperation(
            new UploadMediaOperation(
                $request->file('media'),
                $request->validated(),
                $this->media,
                $this->audit
            ),
            $request->getSecurityContext()
        );
    }

    public function delete(Request $request, int $id): JsonResponse
    {
        return $this->security->executeSecureOperation(
            new DeleteMediaOperation(
                $id,
                $this->media,
                $this->audit
            ),
            $request->getSecurityContext()
        );
    }
}

abstract class BaseController extends Controller
{
    protected function executeSecureOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): JsonResponse {
        try {
            $result = $this->security->executeSecureOperation(
                $operation,
                $context
            );

            return $this->successResponse($result);

        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (AuthorizationException $e) {
            return $this->unauthorizedResponse($e);
        } catch (NotFoundException $e) {
            return $this->notFoundResponse($e);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    protected function successResponse($data): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    protected function errorResponse(\Exception $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode()
            ]
        ], $this->getStatusCode($e));
    }

    protected function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ]
        ], 422);
    }

    protected function unauthorizedResponse(AuthorizationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'message' => 'Unauthorized operation',
                'code' => 403
            ]
        ], 403);
    }

    protected function notFoundResponse(NotFoundException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'message' => 'Resource not found',
                'code' => 404
            ]
        ], 404);
    }

    protected function getStatusCode(\Exception $e): int
    {
        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof AuthorizationException => 403,
            $e instanceof NotFoundException => 404,
            default => 500
        };
    }
}
