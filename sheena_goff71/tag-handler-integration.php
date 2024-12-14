<?php

namespace App\Core\Tag\Services\Integration;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Services\Actions\{
    CreateTagAction,
    UpdateTagAction,
    DeleteTagAction,
    BulkTagAction
};
use App\Core\Tag\Services\Actions\DTOs\{
    TagCreateData,
    TagUpdateData,
    TagActionResponse,
    TagBulkActionResponse
};
use App\Core\Tag\Services\Transformers\TagResponseTransformer;
use Illuminate\Support\Facades\Log;

class TagHandlerIntegration
{
    protected TagResponseTransformer $transformer;
    protected CreateTagAction $createAction;
    protected UpdateTagAction $updateAction;
    protected DeleteTagAction $deleteAction;
    protected BulkTagAction $bulkAction;

    public function __construct(
        TagResponseTransformer $transformer,
        CreateTagAction $createAction,
        UpdateTagAction $updateAction,
        DeleteTagAction $deleteAction,
        BulkTagAction $bulkAction
    ) {
        $this->transformer = $transformer;
        $this->createAction = $createAction;
        $this->updateAction = $updateAction;
        $this->deleteAction = $deleteAction;
        $this->bulkAction = $bulkAction;
    }

    /**
     * Handle tag creation.
     */
    public function handleCreate(array $data): array
    {
        try {
            $tagData = new TagCreateData($data);
            $tag = $this->createAction->execute($tagData);

            $response = TagActionResponse::success(
                'Tag created successfully',
                ['tag' => $tag],
                ['trace_id' => $this->generateTraceId()]
            );

            return $this->transformer->transformActionResponse($response);
        } catch (\Exception $e) {
            Log::error('Tag creation failed', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            $response = TagActionResponse::error(
                $e->getMessage(),
                [],
                ['trace_id' => $this->generateTraceId()]
            );

            return $this->transformer->transformActionResponse($response);
        }
    }

    /**
     * Handle tag update.
     */
    public function handleUpdate(int $id, array $data): array
    {
        try {
            $tagData = new TagUpdateData($id, $data);
            $tag = $this->updateAction->execute($id, $tagData);

            $response = TagActionResponse::success(
                'Tag updated successfully',
                ['tag' => $tag],
                ['trace_id' => $this->generateTraceId()]
            );

            return $this->transformer->transformActionResponse($response);
        } catch (\Exception $e) {
            Log::error('Tag update failed', [
                'tag_id' => $id,
                'error' => $e->getMessage(),
                'data' => $data
            ]);

            $response = TagActionResponse::error(
                $e->getMessage(),
                [],
                ['trace_id' => $this->generateTraceId()]
            );

            return $this->transformer->transformActionResponse($response);
        }
    }

    /**
     * Handle tag deletion.
     */
    public function handleDelete(int $id, bool $force = false): array
    {
        try {
            $result = $this->deleteAction->execute($id, $force);

            $response = TagActionResponse::success(
                'Tag deleted successfully',
                ['success' => $result],
                ['trace_id' => $this->generateTraceId()]
            );

            return $this->transformer->transformActionResponse($response);
        } catch (\Exception $e) {
            Log::error('Tag deletion failed', [
                'tag_id' => $id,
                'error' => $e->getMessage()
            ]);

            $response = TagActionResponse::error(
                $e->getMessage(),
                [],
                ['trace_id' => $this->generateTraceId()]
            );

            return $this->transformer->transformActionResponse($response);
        }
    }

    /**
     * Handle bulk tag operations.
     */
    public function handleBulkOperation(string $action, array $tagIds, array $data = []): array
    {
        try {
            $results = $this->bulkAction->execute($action, $tagIds, $data);

            $response = new TagBulkActionResponse(
                true,
                $results,
                [],
                ['trace_id' => $this->generateTraceId()]
            );

            return $this->transformer->transformBulkActionResponse($response);
        } catch (\Exception $e) {
            Log::error('Bulk tag operation failed', [
                'action' => $action,
                'error' => $e->getMessage()
            ]);

            $response = new TagBulkActionResponse(
                false,
                [],
                ['error' => $e->getMessage()],
                ['trace_id' => $this->generateTraceId()]
            );

            return $this->transformer->transformBulkActionResponse($response);
        }
    }

    /**
     * Generate trace ID for request tracking.
     */
    protected function generateTraceId(): string
    {
        return uniqid('tag_', true);
    }
}
