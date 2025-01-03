<?php

namespace App\Core\Tagging\Exceptions;

use App\Core\Exceptions\CoreException;

class TagException extends CoreException
{
    public static function notFound(int $id): self
    {
        return new self("Tag not found: {$id}");
    }

    public static function invalidData(string $message): self
    {
        return new self("Invalid tag data: {$message}");
    }

    public static function creationFailed(string $reason): self
    {
        return new self("Failed to create tag: {$reason}");
    }

    public static function attachmentFailed(string $reason): self
    {
        return new self("Failed to attach tags: {$reason}");
    }
}

class TagNotFoundException extends TagException {}
class TagValidationException extends TagException {}
class TagAttachmentException extends TagException {}
