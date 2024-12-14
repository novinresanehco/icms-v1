<?php

namespace App\Core\Cache\Exceptions;

class CacheException extends \Exception 
{
}

class CacheValidationException extends CacheException 
{
}

class CacheConnectionException extends CacheException 
{
}

class CacheKeyNotFoundException extends CacheException 
{
}
