<?php

namespace App\Http\Controllers;

use App\Core\Security\{AccessControlService, EncryptionService};
use App\Core\Services\{ContentService, ValidationService};
use App\Http\Requests\{StoreContentRequest, UpdateContentRequest};
use App\Exceptions\{ValidationException, ContentException};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

class ContentController extends Controller
{
    protected AccessControlService $accessControl;
    protected ContentService $contentService;
    protected ValidationService $validator;
    protected EncryptionService $encryption;

    public function __construct(
        AccessControlService $accessControl,
        ContentService $contentService,
        ValidationService $validator,
        EncryptionService $encryption
    ) {
        $this->accessControl = $accessControl;
        $this->contentService = $contentService;
        $this->validator = $validator;
        $this->encryption = $encryption;
    }

    public function store(StoreContentRequest $request): JsonResponse
    {
        $this->authorize('create', Content::class);

        DB::beginTransaction();
        try {
            $validated = $this->validator->validate($request->all());
            
            // Encrypt sensitive data
            if (isset($validated['sensitive_data'])) {
                $validated['sensitive_data'] = $this->encryption->encrypt(
                    $validated['sensitive_data']
                );
            }

            $content = $this->contentService->create($validated);

            DB::commit();
            return response()->json($content, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(UpdateContentRequest $request, int $id): JsonResponse
    {
        $content = $this->contentService->findOrFail($id);
        $this->authorize('update', $content);

        DB::beginTransaction();
        try {
            $validated = $this->validator->validate($request->all());

            if (isset($validated['sensitive_data'])) {
                $validated['sensitive_data'] = $this->encryption->encrypt(
                    $validated['sensitive_data']
                );
            }

            $content = $this->contentService->update($id, $validated);

            DB::commit();
            return response()->json($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $content = $this->contentService->findOrFail($id);
        $this->authorize('delete', $content);

        DB::beginTransaction();
        try {
            $this->contentService->delete($id);
            
            DB::commit();
            return response()->json(null, 204);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Content::class);

        $filters = $this->validator->validate($request->all(), [
            'status' => 'string|in:draft,published,archived',
            'type' => 'string',
            'category_id' => 'integer'
        ]);

        $content = $this->contentService->list($filters);

        // Decrypt any sensitive data for authorized users
        if ($this->accessControl->authorize($request->user(), 'view-sensitive-data')) {
            foreach ($content as $item) {
                if (isset($item->sensitive_data)) {
                    $item->sensitive_data = $this->encryption->decrypt($item->sensitive_data);
                }
            }
        }

        return response()->json($content);
    }

    public function show(int $id): JsonResponse
    {
        $content = $this->contentService->findOrFail($id);
        $this->authorize('view', $content);

        // Decrypt sensitive data if authorized
        if (isset($content->sensitive_data) && 
            $this->accessControl->authorize($request->user(), 'view-sensitive-data')) {
            $content->sensitive_data = $this->encryption->decrypt($content->sensitive_data);
        }

        return response()->json($content);
    }

    public function publish(int $id): JsonResponse
    {
        $content = $this->contentService->findOrFail($id);
        $this->authorize('publish', $content);

        DB::beginTransaction();
        try {
            $content = $this->contentService->publish($id);
            
            DB::commit();
            return response()->json($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function archive(int $id): JsonResponse
    {
        $content = $this->contentService->findOrFail($id);
        $this->authorize('archive', $content);

        DB::beginTransaction();
        try {
            $content = $this->contentService->archive($id);
            
            DB::commit();
            return response()->json($content);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
