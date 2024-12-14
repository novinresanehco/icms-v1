// File: app/Core/File/Manager/FileManager.php
<?php

namespace App\Core\File\Manager;

class FileManager
{
    protected StorageManager $storage;
    protected FileProcessor $processor;
    protected FileValidator $validator;
    protected MetadataExtractor $metadataExtractor;

    public function store(UploadedFile $file, array $options = []): File
    {
        $this->validator->validate($file);
        
        DB::beginTransaction();
        try {
            // Process file
            $processedFile = $this->processor->process($file, $options);
            
            // Extract metadata
            $metadata = $this->metadataExtractor->extract($processedFile);
            
            // Store file
            $path = $this->storage->store($processedFile);
            
            // Create file record
            $fileRecord = $this->createFileRecord($processedFile, $path, $metadata);
            
            DB::commit();
            return $fileRecord;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new FileException("Failed to store file: " . $e->getMessage());
        }
    }

    public function delete(int $fileId): void
    {
        $file = $this->repository->find($fileId);
        
        if (!$file) {
            throw new FileNotFoundException();
        }

        DB::beginTransaction();
        try {
            $this->storage->delete($file->getPath());
            $this->repository->delete($fileId);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new FileException("Failed to delete file: " . $e->getMessage());
        }
    }

    protected function createFileRecord(
        ProcessedFile $file, 
        string $path, 
        array $metadata
    ): File {
        return $this->repository->create([
            'name' => $file->getName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'metadata' => $metadata,
            'created_by' => auth()->id()
        ]);
    }
}

// File: app/Core/File/Storage/StorageManager.php
<?php

namespace App\Core\File\Storage;

class StorageManager
{
    protected DiskManager $diskManager;
    protected PathGenerator $pathGenerator;
    protected StorageConfig $config;

    public function store(ProcessedFile $file): string
    {
        $disk = $this->diskManager->getDisk($file->getType());
        $path = $this->pathGenerator->generate($file);

        try {
            $disk->put($path, $file->getContents(), [
                'visibility' => $this->config->getVisibility(),
                'mime_type' => $file->getMimeType()
            ]);

            return $path;
        } catch (\Exception $e) {
            throw new StorageException("Failed to store file: " . $e->getMessage());
        }
    }

    public function delete(string $path): void
    {
        $disk = $this->diskManager->getDiskByPath($path);
        
        try {
            $disk->delete($path);
        } catch (\Exception $e) {
            throw new StorageException("Failed to delete file: " . $e->getMessage());
        }
    }
}

// File: app/Core/File/Processor/FileProcessor.php
<?php

namespace App\Core\File\Processor;

class FileProcessor
{
    protected array $processors = [];
    protected ProcessorFactory $factory;
    protected ProcessorConfig $config;

    public function process(UploadedFile $file, array $options = []): ProcessedFile
    {
        $processors = $this->getProcessors($file->getMimeType());
        $processedFile = new ProcessedFile($file);

        foreach ($processors as $processor) {
            if ($processor->supports($file)) {
                $processedFile = $processor->process($processedFile, $options);
            }
        }

        return $processedFile;
    }

    protected function getProcessors(string $mimeType): array
    {
        return array_filter($this->processors, function ($processor) use ($mimeType) {
            return $processor->supports($mimeType);
        });
    }
}

// File: app/Core/File/Validator/FileValidator.php
<?php

namespace App\Core\File\Validator;

class FileValidator
{
    protected ValidatorConfig $config;
    protected MimeTypeValidator $mimeTypeValidator;
    protected SizeValidator $sizeValidator;
    protected SecurityScanner $securityScanner;

    public function validate(UploadedFile $file): void
    {
        // Validate mime type
        if (!$this->mimeTypeValidator->validate($file)) {
            throw new ValidationException("Invalid file type");
        }

        // Validate size
        if (!$this->sizeValidator->validate($file)) {
            throw new ValidationException("File size exceeds limit");
        }

        // Security scan
        if (!$this->securityScanner->scan($file)) {
            throw new ValidationException("File failed security scan");
        }
    }
}
