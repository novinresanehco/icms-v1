<?php

namespace App\Core\Services;

use App\Core\Repositories\{MediaRepository, MediaFolderRepository, MediaUsageRepository};
use App\Core\Events\{MediaUploaded, MediaMoved, MediaDeleted};
use App\Core\Exceptions\MediaServiceException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Event, Storage};

class MediaService extends BaseService 
{
    protected MediaFolderRepository $folderRepository;
    protected MediaUsageRepository $usageRepository;
    protected array $validators = [
        MediaFileValidator::class,
        MediaFolderValidator::class
    ];

    public function __construct(
        MediaRepository $repository,
        MediaFolderRepository $folderRepository,
        MediaUsageRepository $usageRepository
    ) {
        parent::__construct($repository);
        $this->folderRepository = $folderRepository;
        $this->usageRepository = $usageRepository;
    }

    public function upload(UploadedFile $file, array $attributes = []): Model
    {
        try {
            $this->validateFile($file);

            DB::beginTransaction();

            $media = $this->repository->createFromUpload($attributes, $file);

            Event::dispatch(new MediaUploaded($media, $file->getPath()));

            DB::commit();

            return $media;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaServiceException("Upload failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function moveToFolder(Model $media, ?int $folderId): bool
    {
        try {
            if ($folderId) {
                $folder = $this->folderRepository->findOrFail($folderId);
            }

            DB::beginTransaction();

            $moved = $this->repository->moveToFolder($media, $folderId);

            if ($moved) {
                Event::dispatch(new MediaMoved($media, $folderId));
            }

            DB::commit();

            return $moved;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaServiceException("Move failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function attachTo(Model $media, Model $model): Model
    {
        try {
            DB::beginTransaction();

            $usage = $this->usageRepository->trackUsage($media, $model);

            DB::commit();

            return $usage;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaServiceException("Attachment failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function detachFrom(Model $media, Model $model): bool
    {
        try {
            DB::beginTransaction();

            $detached = $this->usageRepository->removeUsage($media, $model);

            DB::commit();

            return $detached;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaServiceException("Detachment failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(Model $media): bool
    {
        try {
            DB::beginTransaction();

            $deleted = $this->repository->deleteWithFile($media);

            if ($deleted) {
                Event::dispatch(new MediaDeleted($media));
            }

            DB::commit();

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaServiceException("Deletion failed: {$e->getMessage()}", 0, $e);
        }
    }

    protected function validateFile(UploadedFile $file): void
    {
        $validator = new MediaFileValidator();
        $validator->validate(['file' => $file]);
    }

    public function createFolder(array $attributes): Model
    {
        return $this->folderRepository->create($attributes);
    }

    public function moveFolder(Model $folder, ?int $parentId): bool
    {
        return $this->folderRepository->moveTo($folder, $parentId);
    }

    public function getFolderTree(): Collection
    {
        return $this->folderRepository->getTree();
    }
}
