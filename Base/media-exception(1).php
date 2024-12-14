<?php

namespace App\Exceptions;

use Exception;

class MediaProcessingException extends Exception
{
    protected $message = 'Failed to process media file';
}
