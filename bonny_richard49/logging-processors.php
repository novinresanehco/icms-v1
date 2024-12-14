// app/Core/Logging/Processors/IntrospectionProcessor.php
<?php

namespace App\Core\Logging\Processors;

class IntrospectionProcessor
{
    private int $level;
    private array $skipClassesPartials;
    private int $skipStackFrames;

    public function __construct(
        int $level = 0,
        array $skipClassesPartials = [],
        int $skipStackFrames = 0
    ) {
        $this->level = $level;
        $this->skipClassesPartials = $skipClassesPartials;
        $this->skipStackFrames = $skipStackFrames;
    }

    public function __invoke(array $record): array
    {
        if ($record['level'] < $this->level) {
            return $record;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        $i = 0;
        while (isset($trace[$i])) {
            if (isset($trace[$i]['class'])) {
                foreach ($this->skipClassesPartials as $part) {
                    if (strpos($trace[$i]['class'], $part) !== false) {
                        $i++;
                        continue 2;
                    }
                }
            }
            if ($i >= $this->skipStackFrames) {
                break;
            }
            $i++;
        }

        $record['extra'] = array_merge(
            $record['extra'],
            [
                'file' => isset($trace[$i-1]['file']) ? $trace[$i-1]['file'] : null,
                'line' => isset($trace[$i-1]['line']) ? $trace[$i-1]['line'] : null,
                'class' => isset($trace[$i]['class']) ? $trace[$i]['class'] : null,
                'function' => isset($trace[$i]['function']) ? $trace[$i]['function'] : null
            ]
        );

        return $record;
    }
}

// app/Core/Logging/Processors/MemoryProcessor.php
<?php

namespace App\Core\Logging\Processors;

class MemoryProcessor
{
    private bool $realUsage;
    
    public function __construct(bool $realUsage = true)
    {
        $this->realUsage = $realUsage;
    }

    public function __invoke(array $record): array
    {
        $usage = memory_get_usage($this->realUsage);
        $peak = memory_get_peak_usage($this->realUsage);

        $record['extra'] = array_merge(
            $record['extra'],
            [
                'memory_usage' => $this->formatBytes($usage),
                'memory_peak' => $this->formatBytes($peak)
            ]
        );

        return $record;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// app/Core/Logging/Processors/WebProcessor.php
<?php

namespace App\Core\Logging\Processors;

use Illuminate\Support\Facades\Request;

class WebProcessor
{
    private array $extraFields;
    
    public function __construct(array $extraFields = [])
    {
        $this->extraFields = $extraFields;
    }

    public function __invoke(array $record): array
    {
        $record['extra'] = array_merge(
            $record['extra'],
            [
                'url' => Request::fullUrl(),
                'ip' => Request::ip(),
                'http_method' => Request::method(),
                'server' => Request::server(),
                'user_agent' => Request::userAgent()
            ],
            $this->extraFields ? $this->getExtraFields() : []
        );

        return $record;
    }

    private function getExtraFields(): array
    {
        $fields = [];
        
        foreach ($this->extraFields as $field) {
            $fields[$field] = Request::input($field);
        }
        
        return $fields;
    }
}