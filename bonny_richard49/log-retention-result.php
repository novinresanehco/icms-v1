<?php

namespace App\Core\Logging\Retention;

class RetentionResult
{
    private int $processedCount = 0;
    private int $archivedCount = 0;
    private int $deletedCount = 0;
    private int $compressedCount = 0;
    private int $skippedCount = 0;
    private int $failedCount = 0;
    private array $errors = [];
    private float $startTime;
    private ?float $endTime = null;

    public function __construct()
    {
        $this->start