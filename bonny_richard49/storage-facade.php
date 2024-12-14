<?php

namespace App\Core\Facades;

use Illuminate\Support\Facades\Facade;
use App\Core\Interfaces\StorageServiceInterface;

class Storage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StorageServiceInterface::class;
    }
}
